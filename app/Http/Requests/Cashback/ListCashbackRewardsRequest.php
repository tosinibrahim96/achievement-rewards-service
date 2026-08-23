<?php

declare(strict_types=1);

namespace App\Http\Requests\Cashback;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ListCashbackRewardsRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['page'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
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

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
