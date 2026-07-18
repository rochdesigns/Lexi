<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Setting;
use App\Models\TaskSection;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        Setting::create([
            'user_id' => $user->id,
            'theme' => 'ocean',
            'budget_cycle_days' => 30,
            'budget_cycle_anchor' => now()->toDateString(),
        ]);

        foreach ([['label' => 'Today', 'position' => 1], ['label' => 'This Week', 'position' => 2], ['label' => 'Someday', 'position' => 3]] as $section) {
            TaskSection::create(['user_id' => $user->id, ...$section]);
        }

        return $user;
    }
}
