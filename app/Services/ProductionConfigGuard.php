<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * Asserts the configuration invariants that only matter in production.
 *
 * These are settings whose insecure values are the convenient ones locally —
 * a mailer that writes to the log, a session cookie that survives plain HTTP.
 * Leaving them at those values in production leaks password reset links and
 * session identifiers, so the guard fails the boot rather than serving
 * traffic in that state.
 */
class ProductionConfigGuard
{
    /** Mailers that discard mail instead of delivering it. */
    private const NON_DELIVERING_MAILERS = ['log', 'array', 'null'];

    public function __construct(private Repository $config) {}

    /**
     * Determine whether these invariants apply to the given application.
     *
     * Laravel resolves an unset APP_ENV to `production`, so an application
     * booted before any environment has been established claims production
     * without being it: `composer install` fires package:discover before
     * `composer setup` writes the .env, and a fresh clone never had one.
     * Both would fail on stock defaults.
     *
     * Requiring that production was actually declared — by a real APP_ENV
     * variable, as a container deploy supplies, or by the presence of an env
     * file — lets those boots through while keeping `config:cache` on a real
     * host failing. Where an env file exists but omits APP_ENV, the default
     * is load-bearing and the guard still applies.
     */
    public function appliesTo(Application $app): bool
    {
        if (! $app->isProduction()) {
            return false;
        }

        return Env::get('APP_ENV') !== null || file_exists($app->environmentFilePath());
    }

    /**
     * Assert every production requirement, or throw describing all failures.
     *
     * @throws RuntimeException
     */
    public function assert(): void
    {
        $failures = [...$this->mailFailures(), ...$this->sessionFailures()];

        if ($failures !== []) {
            throw new RuntimeException(
                'Refusing to boot in production with an insecure configuration:'.PHP_EOL
                .'  - '.implode(PHP_EOL.'  - ', $failures)
            );
        }
    }

    /**
     * Get the reasons outbound mail would not reach a real inbox.
     *
     * @return array<int, string>
     */
    private function mailFailures(): array
    {
        $mailer = $this->config->get('mail.default');

        if (in_array($mailer, self::NON_DELIVERING_MAILERS, true)) {
            return ["MAIL_MAILER is [{$mailer}], which discards mail. Password reset and email verification links would be written to the log instead of delivered. Configure a real mailer."];
        }

        if ($mailer === 'smtp' && blank($this->config->get('mail.mailers.smtp.host'))) {
            return ['MAIL_MAILER is [smtp] but MAIL_HOST is empty.'];
        }

        if (blank($this->config->get('mail.from.address'))) {
            return ['MAIL_FROM_ADDRESS is empty.'];
        }

        return [];
    }

    /**
     * Get the reasons the session cookie would be unsafe to serve.
     *
     * @return array<int, string>
     */
    private function sessionFailures(): array
    {
        $failures = [];

        if ($this->config->get('session.secure') !== true) {
            $failures[] = 'SESSION_SECURE_COOKIE is not true, so the session cookie would be sent over plain HTTP.';
        }

        if ($this->config->get('session.encrypt') !== true) {
            $failures[] = 'SESSION_ENCRYPT is not true.';
        }

        if ($this->config->get('session.same_site') !== 'strict') {
            $failures[] = 'SESSION_SAME_SITE is not [strict].';
        }

        return $failures;
    }
}
