<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProviderFailure: string
{
    case RecipientRejected = 'recipient_rejected';
    case Unavailable = 'unavailable';
    case MalformedResponse = 'malformed_response';
    case Timeout = 'timeout';
}
