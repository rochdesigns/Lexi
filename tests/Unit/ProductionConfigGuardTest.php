<?php

use App\Services\ProductionConfigGuard;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;

/**
 * @param  array<string, mixed>  $overrides
 */
function guardFor(array $overrides = []): ProductionConfigGuard
{
    return new ProductionConfigGuard(new Repository(array_replace_recursive([
        'mail' => [
            'default' => 'smtp',
            'mailers' => ['smtp' => ['host' => 'smtp.example.com']],
            'from' => ['address' => 'hello@example.com'],
        ],
        'session' => [
            'secure' => true,
            'encrypt' => true,
            'same_site' => 'strict',
        ],
        'services' => [
            'resend' => ['key' => 're_a_key'],
        ],
    ], $overrides)));
}

test('a correctly configured production environment passes', function () {
    guardFor()->assert();
})->throwsNoExceptions();

test('a mailer that discards mail is rejected', function (string $mailer) {
    expect(fn () => guardFor(['mail' => ['default' => $mailer]])->assert())
        ->toThrow(RuntimeException::class, "MAIL_MAILER is [{$mailer}]");
})->with(['log', 'array', 'null']);

test('smtp without a host is rejected', function () {
    expect(fn () => guardFor(['mail' => ['mailers' => ['smtp' => ['host' => null]]]])->assert())
        ->toThrow(RuntimeException::class, 'MAIL_HOST is empty');
});

test('the resend mailer without an api key is rejected', function () {
    expect(fn () => guardFor([
        'mail' => ['default' => 'resend'],
        'services' => ['resend' => ['key' => null]],
    ])->assert())->toThrow(RuntimeException::class, 'RESEND_API_KEY is empty');
});

test('the resend mailer with an api key passes', function () {
    guardFor(['mail' => ['default' => 'resend']])->assert();
})->throwsNoExceptions();

test('a missing from address is rejected', function () {
    expect(fn () => guardFor(['mail' => ['from' => ['address' => '']]])->assert())
        ->toThrow(RuntimeException::class, 'MAIL_FROM_ADDRESS is empty');
});

test('an insecure session cookie is rejected', function () {
    expect(fn () => guardFor(['session' => ['secure' => null]])->assert())
        ->toThrow(RuntimeException::class, 'SESSION_SECURE_COOKIE is not true');
});

test('an unencrypted session is rejected', function () {
    expect(fn () => guardFor(['session' => ['encrypt' => false]])->assert())
        ->toThrow(RuntimeException::class, 'SESSION_ENCRYPT is not true');
});

test('a lax same site policy is accepted, because emailed links depend on it', function () {
    // Strict withholds the session cookie on a cross-site top-level navigation,
    // which is what clicking a link in a mail client is. The verify route sits
    // behind auth, so the click arrives as a guest and silently fails to verify.
    expect(fn () => guardFor(['session' => ['same_site' => 'lax']])->assert())
        ->not->toThrow(RuntimeException::class);
});

test('a strict same site policy is still accepted', function () {
    expect(fn () => guardFor(['session' => ['same_site' => 'strict']])->assert())
        ->not->toThrow(RuntimeException::class);
});

test('a same site policy of none is rejected', function () {
    expect(fn () => guardFor(['session' => ['same_site' => 'none']])->assert())
        ->toThrow(RuntimeException::class, 'SESSION_SAME_SITE is [none]');
});

test('a missing same site policy is rejected', function () {
    expect(fn () => guardFor(['session' => ['same_site' => null]])->assert())
        ->toThrow(RuntimeException::class, 'SESSION_SAME_SITE');
});

test('every failure is reported together', function () {
    expect(fn () => guardFor([
        'mail' => ['default' => 'log'],
        'session' => ['secure' => false, 'encrypt' => false, 'same_site' => 'none'],
    ])->assert())->toThrow(function (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('MAIL_MAILER is [log]')
            ->toContain('SESSION_SECURE_COOKIE')
            ->toContain('SESSION_ENCRYPT')
            ->toContain('SESSION_SAME_SITE');
    });
});

/**
 * Build an application that reports the given environment.
 *
 * @param  bool  $withEnvFile  whether an env file sits at the environment path
 */
function appIn(string $environment, bool $withEnvFile = true): Application
{
    $app = new Application(sys_get_temp_dir());

    $path = sys_get_temp_dir().'/guard-'.uniqid();
    mkdir($path);
    $app->useEnvironmentPath($path);

    if ($withEnvFile) {
        touch($path.'/.env');
    }

    $app->detectEnvironment(fn (): string => $environment);

    return $app;
}

/**
 * Run a callback with APP_ENV absent from the process environment.
 *
 * Laravel's Env repository is immutable, so clear() on it is a no-op; the
 * underlying storage every adapter reads is what has to go, and come back.
 */
function withoutAppEnv(Closure $callback): void
{
    $previous = Env::get('APP_ENV');

    unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    putenv('APP_ENV');

    try {
        $callback();
    } finally {
        if ($previous !== null) {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $previous;
            putenv('APP_ENV='.$previous);
        }
    }
}

test('the guard applies to a declared production environment', function () {
    expect(guardFor()->appliesTo(appIn('production')))->toBeTrue();
});

test('the guard does not apply outside production', function (string $environment) {
    expect(guardFor()->appliesTo(appIn($environment)))->toBeFalse();
})->with(['local', 'testing', 'staging']);

test('the guard does not apply before any environment exists', function () {
    // `composer install` runs package:discover before `composer setup` writes
    // the .env, and a fresh clone never had one. Laravel resolves the unset
    // APP_ENV to production, so the app claims production on stock defaults.
    withoutAppEnv(function () {
        expect(guardFor(['mail' => ['default' => 'log']])->appliesTo(appIn('production', withEnvFile: false)))
            ->toBeFalse();
    });
});

test('the guard applies when APP_ENV is set without an env file', function () {
    // A container deploy carries real environment variables and no .env.
    expect(guardFor()->appliesTo(appIn('production', withEnvFile: false)))->toBeTrue();
});

test('the guard applies when an env file exists but APP_ENV is unset', function () {
    // A host whose .env omits APP_ENV still resolves to production, and there
    // the default is load-bearing — the guard must not be talked out of it.
    withoutAppEnv(function () {
        expect(guardFor()->appliesTo(appIn('production')))->toBeTrue();
    });
});
