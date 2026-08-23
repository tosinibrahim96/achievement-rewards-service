<?php

declare(strict_types=1);

namespace App\Enums;

enum CashbackTransferErrorCode: string
{
    case InvalidProviderReference = 'invalid_provider_reference';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderInvalidResponse = 'provider_invalid_response';
    case ProviderTransferIdentityMissing = 'provider_transfer_identity_missing';
    case ProviderStatusUnknown = 'provider_status_unknown';
    case OtpRequired = 'otp_required';
    case TransferFailed = 'transfer_failed';
    case TransferReversed = 'transfer_reversed';
    case InsufficientFunds = 'insufficient_funds';
    case RateLimited = 'rate_limited';
    case DuplicateReference = 'duplicate_reference';
    case ProviderRejected = 'provider_rejected';
    case ProviderTimeout = 'provider_timeout';
    case PermanentFailure = 'permanent_failure';
}
