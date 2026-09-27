<?php

use App\Services\ProductionConfigGuard;
use Illuminate\Config\Repository;

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

test('a lax same site policy is rejected', function () {
    expect(fn () => guardFor(['session' => ['same_site' => 'lax']])->assert())
        ->toThrow(RuntimeException::class, 'SESSION_SAME_SITE is not [strict]');
});

test('every failure is reported together', function () {
    expect(fn () => guardFor([
        'mail' => ['default' => 'log'],
        'session' => ['secure' => false, 'encrypt' => false, 'same_site' => 'lax'],
    ])->assert())->toThrow(function (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('MAIL_MAILER is [log]')
            ->toContain('SESSION_SECURE_COOKIE')
            ->toContain('SESSION_ENCRYPT')
            ->toContain('SESSION_SAME_SITE');
    });
});
