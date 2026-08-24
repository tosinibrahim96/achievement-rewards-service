<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\RegisterCustomerInput;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'max:255', Password::min(8), 'confirmed'],
            'device_name' => ['sometimes', 'string', 'max:255'],
            'account_type' => ['missing'],
        ];
    }

    public function toInput(): RegisterCustomerInput
    {
        $validated = $this->safe();
        $deviceName = $validated->string('device_name')->trim()->toString();

        return new RegisterCustomerInput(
            name: $validated->string('name')->toString(),
            email: $validated->string('email')->toString(),
            password: $validated->string('password')->toString(),
            deviceName: $deviceName === '' ? 'api' : $deviceName,
        );
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }
}
