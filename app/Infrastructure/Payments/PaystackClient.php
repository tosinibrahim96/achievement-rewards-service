<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Exceptions\Payments\PaymentProviderException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class PaystackClient
{
    private const string TEST_SECRET_KEY_PREFIX = 'sk_test_';

    private const int FIRST_VISIBLE_ASCII_BYTE = 33;

    private const int LAST_VISIBLE_ASCII_BYTE = 126;

    private const string WEBHOOK_SIGNATURE_ALGORITHM = 'sha512';

    private const int WEBHOOK_SIGNATURE_LENGTH = 128;

    private const string LOWERCASE_HEX_CHARACTERS = '0123456789abcdef';

    private const int NANOSECONDS_PER_MILLISECOND = 1_000_000;

    public function __construct(
        private Factory $http,
        #[Config('payments.paystack.secret_key')]
        #[SensitiveParameter]
        private mixed $secretKey,
        #[Config('payments.paystack.base_url', 'https://api.paystack.co')]
        private string $baseUrl,
        #[Config('payments.paystack.connect_timeout_seconds', 5)]
        private int $connectTimeoutSeconds,
        #[Config('payments.paystack.timeout_seconds', 15)]
        private int $timeoutSeconds,
    ) {}

    /** @param array<string, scalar> $query */
    public function get(
        string $path,
        #[SensitiveParameter] array $query = [],
    ): PaystackResponse {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /** @param array<string, scalar> $payload */
    public function post(
        string $path,
        #[SensitiveParameter] array $payload,
    ): PaystackResponse {
        return $this->send('POST', $path, ['json' => $payload]);
    }

    /** @param array<string, array<string, scalar>> $options */
    private function send(
        string $method,
        string $path,
        #[SensitiveParameter] array $options,
    ): PaystackResponse {
        $startedAt = hrtime(true);

        try {
            $response = $this->request()->send($method, ltrim($path, '/'), $options);
        } catch (ConnectionException $exception) {
            if ($this->isTimeout($exception)) {
                throw PaymentProviderException::timeout();
            }

            throw PaymentProviderException::unavailable();
        }

        if ($response->status() === HttpResponse::HTTP_REQUEST_TIMEOUT
            || $response->status() === HttpResponse::HTTP_GATEWAY_TIMEOUT) {
            throw PaymentProviderException::timeout();
        }

        $latencyMs = max(
            0,
            intdiv(hrtime(true) - $startedAt, self::NANOSECONDS_PER_MILLISECOND),
        );

        return new PaystackResponse(
            httpStatus: $response->status(),
            payload: $this->decode($response),
            latencyMs: $latencyMs,
        );
    }

    public function isConfigured(): bool
    {
        return $this->hasValidTestSecretKey()
            && $this->hasValidBaseUrl()
            && $this->connectTimeoutSeconds > 0
            && $this->timeoutSeconds >= $this->connectTimeoutSeconds;
    }

    public function hasValidTestSecretKey(): bool
    {
        if (! is_string($this->secretKey)
            || ! str_starts_with($this->secretKey, self::TEST_SECRET_KEY_PREFIX)
            || strlen($this->secretKey) === strlen(self::TEST_SECRET_KEY_PREFIX)) {
            return false;
        }

        $secretLength = strlen($this->secretKey);

        /*
         * A secret suffix may contain visible ASCII from `!` (byte 33) through
         * `~` (byte 126). Space, control bytes, DEL, and non-ASCII are rejected
         * before the secret can be placed in an Authorization header.
         */
        for ($index = strlen(self::TEST_SECRET_KEY_PREFIX); $index < $secretLength; $index++) {
            $byte = ord($this->secretKey[$index]);

            if ($byte < self::FIRST_VISIBLE_ASCII_BYTE
                || $byte > self::LAST_VISIBLE_ASCII_BYTE) {
                return false;
            }
        }

        return true;
    }

    public function signatureMatchesBody(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] string $signature,
    ): bool {
        if (! $this->hasValidTestSecretKey() || ! $this->hasExpectedSignatureFormat($signature)) {
            return false;
        }

        /** @var non-empty-string $secretKey */
        $secretKey = $this->secretKey;

        /*
         * HMAC combines the shared secret with the exact request bytes. SHA-512
         * returns 128 lowercase hexadecimal characters here, and hash_equals()
         * compares them without stopping early based on matching content.
         * This authenticates the bytes; it does not encrypt them or stop replay.
         */
        $expectedSignature = hash_hmac(
            self::WEBHOOK_SIGNATURE_ALGORITHM,
            $rawBody,
            $secretKey,
        );

        return hash_equals($expectedSignature, $signature);
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw PaymentProviderException::unavailable();
        }

        /** @var non-empty-string $secretKey */
        $secretKey = $this->secretKey;

        return $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($secretKey)
            ->withoutRedirecting()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds);
    }

    /** @return array<string, mixed> */
    private function decode(
        #[SensitiveParameter] Response $response,
    ): array {
        try {
            $payload = $response->json(flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($response->status() === HttpResponse::HTTP_UNAUTHORIZED
                || $response->status() === HttpResponse::HTTP_FORBIDDEN
                || $response->status() === HttpResponse::HTTP_TOO_MANY_REQUESTS
                || $response->serverError()) {
                throw PaymentProviderException::unavailable();
            }

            throw PaymentProviderException::malformedResponse();
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw PaymentProviderException::malformedResponse();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function hasValidBaseUrl(): bool
    {
        $parts = parse_url($this->baseUrl);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === '') {
            return false;
        }

        $path = $parts['path'] ?? null;

        return ($path === null || $path === '' || $path === '/')
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts);
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        return str_contains($exception->getMessage(), 'cURL error 28');
    }

    private function hasExpectedSignatureFormat(string $signature): bool
    {
        if (strlen($signature) !== self::WEBHOOK_SIGNATURE_LENGTH) {
            return false;
        }

        for ($index = 0; $index < self::WEBHOOK_SIGNATURE_LENGTH; $index++) {
            if (! str_contains(self::LOWERCASE_HEX_CHARACTERS, $signature[$index])) {
                return false;
            }
        }

        return true;
    }
}
