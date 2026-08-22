<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\AccountType;
use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('account_type', AccountType::Customer->value),
            ],
            'external_reference' => ['required', 'string', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'completed_at' => ['required', 'date'],
        ];
    }

    public function toInput(): RecordPurchaseInput
    {
        $validated = $this->safe();

        return new RecordPurchaseInput(
            userId: $validated->integer('user_id'),
            externalReference: $validated->string('external_reference')->toString(),
            amount: new Money(
                amountMinor: $validated->integer('amount_minor'),
                currency: Currency::from($validated->string('currency')->toString()),
            ),
            completedAt: CarbonImmutable::parse($validated->string('completed_at')->toString())->utc(),
        );
    }

    protected function prepareForValidation(): void
    {
        $externalReference = $this->input('external_reference');
        $currency = $this->input('currency');

        $this->merge([
            'external_reference' => is_string($externalReference) ? trim($externalReference) : $externalReference,
            'currency' => is_string($currency) ? strtoupper(trim($currency)) : $currency,
        ]);
    }
}
