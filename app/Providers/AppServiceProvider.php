<?php

namespace App\Providers;

use App\Services\ProductionConfigGuard;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Statuses that get a rendered error page rather than a bare response.
     *
     * @var list<int>
     */
    private const ERROR_PAGE_STATUSES = [403, 404, 419, 429, 500, 503];

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
        $this->configureDefaults();
        $this->assertProductionConfiguration();
        $this->configureErrorPages();
        $this->configureMcp();
    }

    /**
     * Fail the boot rather than serve production traffic on insecure config.
     *
     * Running in console is included on purpose: it makes `config:cache`
     * during a deploy the thing that fails, so a misconfigured release never
     * takes over from a working one.
     */
    protected function assertProductionConfiguration(): void
    {
        $guard = $this->app->make(ProductionConfigGuard::class);

        if ($guard->appliesTo(app())) {
            $guard->assert();
        }
    }

    /**
     * Render error responses as an Inertia page inside the app shell.
     *
     * Without this, every `abort_if` in the application renders a bare
     * Symfony page with no styling, navigation or way back. Requests that
     * want JSON are left alone so API clients still get JSON.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            if ($response->request->expectsJson()) {
                return null;
            }

            if (! in_array($response->statusCode(), self::ERROR_PAGE_STATUSES, true)) {
                return null;
            }

            return $response
                ->render('ErrorPage', ['status' => $response->statusCode()])
                ->withSharedData();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Wire up the MCP server's OAuth consent screen, token lifetimes and rate limit.
     *
     * The limit is per user, so one noisy connection cannot starve another.
     */
    protected function configureMcp(): void
    {
        Passport::authorizationView(fn (array $parameters): Response => response()->view('mcp.authorize', $parameters));

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
    }
}
