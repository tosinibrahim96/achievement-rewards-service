<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened when we handled one webhook. Applied may mean success, failure,
 * or reversal.
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
