<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\LoginCustomerInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function toInput(): LoginCustomerInput
    {
        $validated = $this->safe();
        $deviceName = $validated->string('device_name')->trim()->toString();

        return new LoginCustomerInput(
            email: $validated->string('email')->toString(),
            password: $validated->string('password')->toString(),
            deviceName: $deviceName === '' ? 'api' : $deviceName,
        );
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }
}
