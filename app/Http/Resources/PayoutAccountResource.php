<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PayoutAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PayoutAccount $payoutAccount */
        $payoutAccount = $this->resource;

        return [
            'id' => $payoutAccount->id,
            'provider' => $payoutAccount->provider->value,
            'account_name' => $payoutAccount->account_name,
            'bank_name' => $payoutAccount->bank_name,
            'bank_code' => $payoutAccount->bank_code,
            'masked_account_number' => '******'.$payoutAccount->account_last_four,
            'currency' => $payoutAccount->currency->value,
            'verified_at' => $payoutAccount->verified_at->utc()->toISOString(),
        ];
    }
}
