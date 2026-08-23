<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class PaystackResponse
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $httpStatus,
        #[SensitiveParameter] private array $payload,
        public int $latencyMs,
    ) {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new InvalidArgumentException('A Paystack HTTP status must be between 100 and 599.');
        }

        if ($latencyMs < 0) {
            throw new InvalidArgumentException('Paystack response latency cannot be negative.');
        }
    }

    public function operationSucceeded(): ?bool
    {
        return match ($this->payload['status'] ?? null) {
            true => true,
            false, 'false' => false,
            default => null,
        };
    }

    public function data(): mixed
    {
        return $this->payload['data'] ?? null;
    }

    public function message(): ?string
    {
        return $this->nonEmptyString($this->payload['message'] ?? null);
    }

    public function providerCode(): ?string
    {
        return $this->nonEmptyString($this->payload['code'] ?? null);
    }

    public function hasSuccessfulHttpStatus(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public function hasClientErrorHttpStatus(): bool
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500;
    }

    public function hasServerErrorHttpStatus(): bool
    {
        return $this->httpStatus >= 500;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
