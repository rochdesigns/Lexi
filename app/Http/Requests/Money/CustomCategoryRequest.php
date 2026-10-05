<?php

namespace App\Http\Requests\Money;

use App\Models\Money\TransactionCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CustomCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * A name can't repeat a built-in category or one of the user's own, however it is capitalised.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                $taken = collect(TransactionCategory::expenseValues())
                    ->merge($this->user()->customCategories()->pluck('name'))
                    ->contains(fn (string $name): bool => mb_strtolower($name) === mb_strtolower((string) $value));

                if ($taken) {
                    $fail('You already have a category with that name.');
                }
            }],
        ];
    }
}
