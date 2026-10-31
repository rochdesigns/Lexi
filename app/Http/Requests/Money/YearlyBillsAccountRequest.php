<?php

namespace App\Http\Requests\Money;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class YearlyBillsAccountRequest extends FormRequest
{
    /**
     * Null clears the account.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['present', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
