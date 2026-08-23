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
    public function __construct(
        private Factory $http,
        #[Config('payments.paystack.secret_key')]
        #[SensitiveParameter]
        private ?string $secretKey,
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

        $latencyMs = max(0, intdiv(hrtime(true) - $startedAt, 1_000_000));

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

    private function hasValidTestSecretKey(): bool
    {
        if ($this->secretKey === null) {
            return false;
        }

        return preg_match('/\Ask_test_[\x21-\x7E]+\z/', $this->secretKey) === 1;
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
}
