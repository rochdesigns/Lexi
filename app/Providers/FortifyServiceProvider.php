<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        RateLimiter::for('fortify', function (Request $request): array|Limit {
            return match (true) {
                $request->routeIs('register.store') => $this->registrationLimits($request),
                $request->routeIs('password.email') => $this->passwordResetLinkLimits($request),
                $request->routeIs('password.update') => Limit::perMinute(5)->by($this->throttleKeyFor($request)),
                default => Limit::none(),
            };
        });
    }

    /**
     * Get the limits applied to account creation.
     *
     * Fortify offers no limiter hook for its registration route, so the
     * 'fortify' limiter is attached to every Fortify route and narrowed
     * by route name. Without this, account creation is unlimited.
     *
     * @return array<int, Limit>
     */
    private function registrationLimits(Request $request): array
    {
        return [
            Limit::perMinute(5)->by($this->throttleKeyFor($request)),
            Limit::perHour(20)->by($this->throttleKeyFor($request)),
        ];
    }

    /**
     * Get the limits applied to password reset link requests.
     *
     * Limited per address as well as per IP, so that rotating IPs cannot be
     * used to mail-bomb one address, and one IP cannot enumerate many.
     *
     * @return array<int, Limit>
     */
    private function passwordResetLinkLimits(Request $request): array
    {
        $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

        return [
            Limit::perMinute(3)->by($email.'|'.$this->throttleKeyFor($request)),
            Limit::perHour(10)->by($this->throttleKeyFor($request)),
            Limit::perHour(5)->by('password-reset:'.$email),
        ];
    }

    /**
     * Get the per-client throttle key for an unauthenticated request.
     */
    private function throttleKeyFor(Request $request): string
    {
        return (string) $request->ip();
    }
}
