<?php

namespace App\Http\Requests\Money;

use App\Models\Money\AccountType;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    /**
     * The balance arrives in pesos.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'balance' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'is_spendable' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        $data = $this->validated();

        return [...$data, 'balance' => Centavos::fromPesos($data['balance'])];
    }
}
