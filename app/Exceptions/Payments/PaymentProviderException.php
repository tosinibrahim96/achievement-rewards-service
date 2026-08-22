<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use App\Enums\PaymentProviderFailure;
use RuntimeException;

final class PaymentProviderException extends RuntimeException
{
    private function __construct(public readonly PaymentProviderFailure $failure)
    {
        parent::__construct($failure->value);
    }

    public static function recipientRejected(): self
    {
        return new self(PaymentProviderFailure::RecipientRejected);
    }

    public static function unavailable(): self
    {
        return new self(PaymentProviderFailure::Unavailable);
    }

    public static function malformedResponse(): self
    {
        return new self(PaymentProviderFailure::MalformedResponse);
    }

    public static function timeout(): self
    {
        return new self(PaymentProviderFailure::Timeout);
    }
}
