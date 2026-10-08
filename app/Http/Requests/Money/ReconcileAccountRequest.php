<?php

namespace App\Http\Requests\Money;

use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileAccountRequest extends FormRequest
{
    /**
     * The balance the bank shows, in pesos.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'balance' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ];
    }

    public function actualBalance(): int
    {
        return Centavos::fromPesos($this->validated('balance'));
    }
}
