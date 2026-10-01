<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /**
         * Answer a verification link whose signature no longer holds.
         *
         * A link is good for an hour from delivery, so the ordinary way to
         * arrive here is to open the mail later than that. Left alone it
         * renders the 403 page, which tells the user the link belongs to
         * another account and offers a way back that lands them on the
         * verification notice again -- wrong on the facts, and a dead end
         * besides. The link is theirs; it is only stale.
         *
         * `auth:web` runs ahead of `signed` on this route, so whoever gets
         * here is logged in and the address is known: either it is already
         * verified, which is worth saying, or a fresh link costs nothing.
         * A link addressed to someone else is left alone, so it goes on
         * rendering the 403 that `VerifyEmailRequest` gives a live one.
         *
         * The route's own `throttle:6,1` sits behind `signed` and so never
         * runs on this path. The limiter below stands in for it, so that a
         * stale link cannot become an uncapped way to send mail.
         */
        $exceptions->render(function (InvalidSignatureException $e, Request $request): ?RedirectResponse {
            if (! $request->routeIs('verification.verify') || ! $request->user()) {
                return null;
            }

            $user = $request->user();

            $isTheirLink = (string) $request->route('id') === (string) $user->getKey()
                && hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'));

            if (! $isTheirLink) {
                return null;
            }

            if ($user->hasVerifiedEmail()) {
                Inertia::flash('toast', [
                    'type' => 'info',
                    'message' => __('That link has expired, but your email address is already verified.'),
                ]);

                return redirect()->route('today');
            }

            RateLimiter::attempt(
                'verification-link-rejected:'.$user->getAuthIdentifier(),
                maxAttempts: 6,
                callback: fn () => $user->sendEmailVerificationNotification(),
            );

            return redirect()->route('verification.notice')->with('status', 'verification-link-expired');
        });
    })->create();
