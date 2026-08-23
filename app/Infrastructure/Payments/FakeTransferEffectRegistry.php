<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Redis;
use JsonException;
use LogicException;
use RuntimeException;

final readonly class FakeTransferEffectRegistry
{
    private const int RECORD_VERSION = 1;

    private const string HASH_ALGORITHM = 'sha256';

    private const int FAKE_LATENCY_MS = 0;

    private const string SAFE_KEY_PART_PATTERN = '/\A[a-zA-Z0-9._-]+\z/';

    public function __construct(
        #[Config('app.env', 'production')] private string $environment,
        #[Config('payments.fake.transfer_effect_namespace', 'default')] private string $namespace,
    ) {
        $this->ensureSafeKeyPart($this->environment, 'application environment');
        $this->ensureSafeKeyPart($this->namespace, 'fake transfer effect namespace');
    }

    public function findForRequest(CashbackTransferRequest $request): ?CashbackTransferResult
    {
        $record = $this->readRecord($request->providerReference);

        if ($record === null) {
            return null;
        }

        $storedFingerprint = $record['request_fingerprint'] ?? null;

        if (! is_string($storedFingerprint)
            || ! hash_equals($storedFingerprint, $this->requestFingerprint($request))) {
            throw new LogicException('The fake transfer reference is already bound to different payout details.');
        }

        return $this->resultFromRecord($record);
    }

    public function findByReference(string $providerReference): ?CashbackTransferResult
    {
        $record = $this->readRecord($providerReference);

        return $record === null ? null : $this->resultFromRecord($record);
    }

    public function create(
        CashbackTransferRequest $request,
        PayoutAttemptStatus $status,
    ): CashbackTransferResult {
        if (! in_array($status, [PayoutAttemptStatus::Succeeded, PayoutAttemptStatus::Pending], true)) {
            throw new LogicException('Only a provider-created fake transfer may consume a reference.');
        }

        $record = [
            'version' => self::RECORD_VERSION,
            'request_fingerprint' => $this->requestFingerprint($request),
            'status' => $status->value,
            'transfer_code' => 'TRF_FAKE_'.hash(self::HASH_ALGORITHM, $request->providerReference),
        ];

        try {
            $payload = json_encode($record, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The fake transfer effect could not be encoded.', previous: $exception);
        }

        /*
         * SETNX lets only the first contender save the simulated provider effect.
         * Ignore its winner flag and reread: every contender must return the same
         * stored result and verify that the reference still means the same request.
         */
        Redis::connection('default')->command('setnx', [
            $this->keyForReference($request->providerReference),
            $payload,
        ]);

        $stored = $this->findForRequest($request);

        if ($stored === null) {
            throw new RuntimeException('The fake transfer effect could not be persisted.');
        }

        return $stored;
    }

    public function forget(string $providerReference): void
    {
        Redis::connection('default')->command('del', [$this->keyForReference($providerReference)]);
    }

    public function keyForReference(string $providerReference): string
    {
        return sprintf(
            'cashback:fake-transfer-effect:%s:%s:%s',
            $this->environment,
            $this->namespace,
            hash(self::HASH_ALGORITHM, $providerReference),
        );
    }

    /** @return array<string, mixed>|null */
    private function readRecord(string $providerReference): ?array
    {
        $payload = Redis::connection('default')->command('get', [
            $this->keyForReference($providerReference),
        ]);

        if ($payload === null || $payload === false) {
            return null;
        }

        if (! is_string($payload)) {
            throw new RuntimeException('The fake transfer effect has an invalid stored representation.');
        }

        try {
            $record = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The fake transfer effect could not be decoded.', previous: $exception);
        }

        if (! is_array($record)
            || ($record['version'] ?? null) !== self::RECORD_VERSION) {
            throw new RuntimeException('The fake transfer effect has an invalid stored representation.');
        }

        /** @var array<string, mixed> $record */
        return $record;
    }

    /** @param array<string, mixed> $record */
    private function resultFromRecord(array $record): CashbackTransferResult
    {
        $statusValue = $record['status'] ?? null;
        $transferCode = $record['transfer_code'] ?? null;
        $status = is_string($statusValue) ? PayoutAttemptStatus::tryFrom($statusValue) : null;

        if (! in_array($status, [PayoutAttemptStatus::Succeeded, PayoutAttemptStatus::Pending], true)
            || ! is_string($transferCode)
            || $transferCode === '') {
            throw new RuntimeException('The fake transfer effect has an invalid stored representation.');
        }

        return new CashbackTransferResult(
            status: $status,
            transferCode: $transferCode,
            httpStatus: null,
            errorCode: null,
            errorMessage: null,
            latencyMs: self::FAKE_LATENCY_MS,
            observedBalanceMinor: null,
        );
    }

    private function requestFingerprint(CashbackTransferRequest $request): string
    {
        /*
         * The stable reference is idempotent only for these exact payout facts.
         * The fingerprint makes conflicting reuse fail instead of returning a
         * simulated transfer created for a different recipient or amount.
         */
        return hash(self::HASH_ALGORITHM, implode('|', [
            PaymentProvider::Fake->value,
            $request->providerReference,
            $request->recipientCode,
            (string) $request->amountMinor,
            $request->currency->value,
        ]));
    }

    private function ensureSafeKeyPart(string $value, string $description): void
    {
        /*
         * These values become colon-separated Redis key parts. Whole-string
         * matching permits familiar names without allowing another key segment.
         */
        if ($value === '' || preg_match(self::SAFE_KEY_PART_PATTERN, $value) !== 1) {
            throw new LogicException("The {$description} must use only letters, numbers, dots, underscores, or hyphens.");
        }
    }
}
