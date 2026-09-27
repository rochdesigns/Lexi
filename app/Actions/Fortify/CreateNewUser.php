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
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the terms of service and privacy policy to continue.',
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Not fillable: consent is recorded by the application, never taken
        // from request input.
        $user->forceFill(['terms_accepted_at' => now()])->save();

        Setting::create([
            'user_id' => $user->id,
            'theme' => 'ocean',
        ]);

        foreach ([['label' => 'Today', 'position' => 1], ['label' => 'This Week', 'position' => 2], ['label' => 'Someday', 'position' => 3]] as $section) {
            TaskSection::create(['user_id' => $user->id, ...$section]);
        }

        return $user;
    }
}
