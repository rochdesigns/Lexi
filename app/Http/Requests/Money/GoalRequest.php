<?php

namespace App\Http\Requests\Money;

use App\Models\Money\Goal;
use App\Models\Money\GoalKind;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GoalRequest extends FormRequest
{
    public const BUFFER_MONTHS = [1, 2, 3, 4, 5, 6, 9, 12, 18, 24];

    /**
     * Money arrives in pesos. The kind is fixed once a goal exists. A buffer
     * is sized in months, a sinking fund has a target amount and an optional date.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isBuffer = $this->kind() === GoalKind::Buffer;

        return [
            'kind' => ['required', Rule::enum(GoalKind::class)],
            'name' => [$isBuffer ? 'nullable' : 'required', 'string', 'max:100'],
            'monthly_contribution' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'saved' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'buffer_months' => $isBuffer ? ['required', 'integer', Rule::in(self::BUFFER_MONTHS)] : ['prohibited'],
            'target_amount' => $isBuffer ? ['prohibited'] : ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'target_date' => $isBuffer ? ['prohibited'] : ['nullable', 'date'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('goal') === null && $this->kind() === GoalKind::Buffer && $this->user()->goals()->where('kind', GoalKind::Buffer)->exists()) {
                $validator->errors()->add('kind', 'You already have an emergency buffer. Edit it instead.');
            }
        }];
    }

    /**
     * A goal's kind cannot change, so an edit keeps the one it has.
     */
    public function kind(): ?GoalKind
    {
        $goal = $this->route('goal');

        return $goal instanceof Goal ? $goal->kind : GoalKind::tryFrom((string) $this->input('kind'));
    }

    /**
     * @return array<string, mixed>
     */
    public function goalAttributes(): array
    {
        $data = $this->validated();
        $kind = $this->kind();
        $linked = ($data['account_id'] ?? null) !== null;

        return [
            'kind' => $kind,
            'name' => $kind === GoalKind::Buffer ? ($data['name'] ?? null) ?: 'Emergency buffer' : $data['name'],
            'monthly_contribution' => Centavos::fromPesos($data['monthly_contribution']),
            'saved' => $linked ? 0 : Centavos::fromPesos($data['saved'] ?? 0),
            'account_id' => $data['account_id'] ?? null,
            'buffer_months' => $data['buffer_months'] ?? null,
            'target_amount' => isset($data['target_amount']) ? Centavos::fromPesos($data['target_amount']) : null,
            'target_date' => $data['target_date'] ?? null,
        ];
    }
}
