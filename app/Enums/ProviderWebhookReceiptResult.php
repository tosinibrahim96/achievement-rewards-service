<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the webhook handler did with one delivery; Applied may record failure or reversal facts.
 */
enum ProviderWebhookReceiptResult: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Invalid = 'invalid';
    case Unsupported = 'unsupported';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
}
