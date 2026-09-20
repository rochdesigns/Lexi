<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\DebtFocus\Account as DebtFocusAccount;
use App\Models\DebtFocus\Bill as DebtFocusBill;
use App\Models\DebtFocus\Debt as DebtFocusDebt;
use App\Models\DebtFocus\Envelope as DebtFocusEnvelope;
use App\Models\DebtFocus\Settings as DebtFocusSettings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function healthMetrics(): HasMany
    {
        return $this->hasMany(HealthMetric::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function visionBoardItems(): HasMany
    {
        return $this->hasMany(VisionBoardItem::class);
    }

    public function taskSections(): HasMany
    {
        return $this->hasMany(TaskSection::class);
    }

    /**
     * @return HasMany<DebtFocusDebt, $this>
     */
    public function debtFocusDebts(): HasMany
    {
        return $this->hasMany(DebtFocusDebt::class);
    }

    /**
     * @return HasOne<DebtFocusSettings, $this>
     */
    public function debtFocusSettings(): HasOne
    {
        return $this->hasOne(DebtFocusSettings::class);
    }

    /**
     * @return HasMany<DebtFocusAccount, $this>
     */
    public function debtFocusAccounts(): HasMany
    {
        return $this->hasMany(DebtFocusAccount::class);
    }

    /**
     * @return HasMany<DebtFocusBill, $this>
     */
    public function debtFocusBills(): HasMany
    {
        return $this->hasMany(DebtFocusBill::class);
    }

    /**
     * @return HasMany<DebtFocusEnvelope, $this>
     */
    public function debtFocusEnvelopes(): HasMany
    {
        return $this->hasMany(DebtFocusEnvelope::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
