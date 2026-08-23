<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Exceptions\Webhooks\InvalidWebhookSignatureException;
use App\Exceptions\Webhooks\WebhookPayloadTooLargeException;
use App\Exceptions\Webhooks\WebhookVerificationUnavailableException;
use App\Infrastructure\Payments\PaystackClient;
use SensitiveParameter;

final readonly class VerifyPaystackWebhookSignature
{
    public const int MAX_BODY_BYTES = 65_536;

    public function __construct(private PaystackClient $paystack) {}

    public function handle(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] ?string $signature,
    ): void {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new WebhookPayloadTooLargeException;
        }

        if (! $this->paystack->hasValidTestSecretKey()) {
            throw new WebhookVerificationUnavailableException;
        }

        if ($signature === null
            || ! $this->paystack->matchesWebhookSignature($rawBody, $signature)) {
            throw new InvalidWebhookSignatureException;
        }
    }
}
