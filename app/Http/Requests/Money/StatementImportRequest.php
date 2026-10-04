<?php

namespace App\Http\Requests\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Services\Money\StatementParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StatementImportRequest extends FormRequest
{
    public const MAX_ROWS = 500;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'debt_id' => ['required', Rule::exists('debts', 'id')->where('user_id', $this->user()->id)->where('type', DebtType::CreditCard->value)],
            'csv' => ['required', 'string', 'max:300000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $parsed = app(StatementParser::class)->parse($this->string('csv')->toString());

            if (count($parsed['rows']) + count($parsed['skipped']) > self::MAX_ROWS) {
                $validator->errors()->add('csv', 'That is more than '.self::MAX_ROWS.' rows. Import the statement in parts.');
            } elseif ($parsed['rows'] === []) {
                $validator->errors()->add('csv', 'No charges found. Each line needs a date, a description and an amount.');
            }
        }];
    }

    public function card(): Debt
    {
        return $this->user()->debts()->findOrFail($this->integer('debt_id'));
    }
}
