<?php

namespace App\Http\Requests\Money;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecoveryRequest extends FormRequest
{
    public const MONTH_OPTIONS = [3, 6, 9, 12];

    /**
     * Null turns recovery mode off.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'months' => ['present', 'nullable', 'integer', Rule::in(self::MONTH_OPTIONS)],
        ];
    }
}
