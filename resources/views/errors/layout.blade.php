{{--
    Fallback error page.

    The application normally renders errors as an Inertia page so they keep the
    app shell. This view is what serves when that is not possible — a broken
    Vite build, maintenance mode, or a failure early in the boot — so it
    deliberately depends on nothing but inline CSS.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('code') — @yield('title') · {{ config('app.name', 'Lexi') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <style>
            :root {
                --app-bg: #efece4;
                --card-bg: #fffdf8;
                --card-border: #e0dacd;
                --ink: #211d17;
                --muted: #8a8172;
                --faint: #a49b8a;
                --btn-bg: #211d17;
                --btn-ink: #fdfbf5;
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 4rem 1rem;
                background: var(--app-bg);
                color: var(--ink);
                font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                line-height: 1.5;
                -webkit-font-smoothing: antialiased;
            }

            .card {
                width: 100%;
                max-width: 28rem;
                padding: 2.5rem 1.75rem;
                border: 1px solid var(--card-border);
                border-radius: 1rem;
                background: var(--card-bg);
                text-align: center;
            }

            .code {
                margin: 0;
                font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 3rem;
                font-weight: 600;
                letter-spacing: -0.02em;
                color: var(--faint);
            }

            h1 {
                margin: 1rem 0 0;
                font-size: 1.25rem;
                font-weight: 600;
                letter-spacing: -0.01em;
            }

            p {
                margin: 0.75rem 0 0;
                font-size: 0.875rem;
                color: var(--muted);
            }

            a.action {
                display: inline-block;
                margin-top: 2rem;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                background: var(--btn-bg);
                color: var(--btn-ink);
                font-size: 0.875rem;
                font-weight: 500;
                text-decoration: none;
            }

            a.action:hover { opacity: 0.9; }

            @media (min-width: 640px) {
                .card { padding: 2.5rem; }
            }
        </style>
    </head>
    <body>
        <main class="card">
            <p class="code">@yield('code')</p>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>

            @hasSection('action')
                @yield('action')
            @else
                <a class="action" href="{{ url('/') }}">Go to the homepage</a>
            @endif
        </main>
    </body>
</html>
