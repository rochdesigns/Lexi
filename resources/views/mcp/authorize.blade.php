{{--
    OAuth consent screen for the Money MCP server.

    Deliberately depends on nothing but inline CSS (no Vite), in the same
    palette as the error pages, so it renders even when the front-end build
    is unavailable.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connect {{ $client->name }} · {{ config('app.name', 'Lexi') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            --app-bg: #efece4;
            --card-bg: #fffdf8;
            --card-border: #e0dacd;
            --ink: #211d17;
            --muted: #8a8172;
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

        h1 { margin: 0; font-size: 1.25rem; font-weight: 600; letter-spacing: -0.01em; }
        p { margin: 0.75rem 0 0; font-size: 0.875rem; color: var(--muted); }
        p strong { color: var(--ink); font-weight: 600; }
        .account { margin-top: 1.5rem; padding: 0.75rem; border: 1px solid var(--card-border); border-radius: 0.5rem; }
        .account p { margin: 0; }

        .actions { display: flex; gap: 0.75rem; margin-top: 2rem; }
        .actions form { flex: 1; }

        button {
            width: 100%;
            padding: 0.625rem 1.25rem;
            border: 1px solid var(--btn-bg);
            border-radius: 0.5rem;
            background: var(--btn-bg);
            color: var(--btn-ink);
            font: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
        }

        button.secondary { background: transparent; color: var(--ink); border-color: var(--card-border); }
        button:hover { opacity: 0.9; }
        button:disabled { opacity: 0.5; cursor: default; }

        @media (min-width: 640px) {
            .card { padding: 2.5rem; }
        }
    </style>
</head>
<body>
@php
    $redirectHost = parse_url((string) request()->query('redirect_uri'), PHP_URL_HOST);
@endphp
<main class="card">
    <h1>Connect {{ $client->name }} to Lexi</h1>

    @php($canWrite = collect($scopes ?? [])->contains(fn ($scope) => (is_object($scope) ? $scope->id : $scope) === 'mcp:write'))
    <p>
        This lets {{ $client->name }} read <strong>Your Money data</strong> (debts, accounts, bills, income and goals).
        @if ($canWrite)
            It can also <strong>record</strong> expenses, income and debt payments, which changes your account balances and payment history. It cannot edit or delete anything.
        @else
            It is read-only: {{ $client->name }} cannot add, change or delete anything.
        @endif
    </p>

    @if (is_string($redirectHost) && $redirectHost !== '')
        <p>When you authorize, {{ $client->name }} will be sent back to <strong>{{ $redirectHost }}</strong>.</p>
    @endif

    <div class="account">
        <p>Signed in as <strong>{{ $user->email }}</strong></p>
    </div>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="secondary">Cancel</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" id="authorizeButton">Authorize</button>
        </form>
    </div>
</main>

<script>
    document.getElementById('authorizeForm').addEventListener('submit', function () {
        var button = document.getElementById('authorizeButton');
        button.disabled = true;
        button.textContent = 'Authorizing...';
    });
</script>
</body>
</html>
