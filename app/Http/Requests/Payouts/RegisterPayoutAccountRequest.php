<?php

declare(strict_types=1);

namespace App\Http\Requests\Payouts;

use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Models\PayoutAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class RegisterPayoutAccountRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['account_number', 'bank_code'];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $payoutAccount = $user->payoutAccount()->first()
            ?? new PayoutAccount(['user_id' => $user->id]);

        return $user->can('update', $payoutAccount);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
            'bank_code' => ['required', 'string', 'regex:/\A[0-9]{3}\z/'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, self::ALLOWED_FIELDS, true)) {
                    $validator->errors()->add((string) $field, 'The field is not allowed.');
                }
            }
        }];
    }

    public function toInput(): RegisterPayoutAccountInput
    {
        $validated = $this->safe();

        return new RegisterPayoutAccountInput(
            accountNumber: $validated->string('account_number')->toString(),
            bankCode: $validated->string('bank_code')->toString(),
        );
    }

    protected function prepareForValidation(): void
    {
        $accountNumber = $this->input('account_number');
        $bankCode = $this->input('bank_code');

        $this->merge([
            'account_number' => is_string($accountNumber) ? trim($accountNumber) : $accountNumber,
            'bank_code' => is_string($bankCode) ? trim($bankCode) : $bankCode,
        ]);
    }
}
