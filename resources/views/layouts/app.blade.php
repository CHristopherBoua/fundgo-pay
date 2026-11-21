<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <style>
        :root {
            --bg: #f6f5f2; --surface: #ffffff; --text: #1d1d1b; --muted: #6b6a66; --line: #e4e2dc;
            --brand: #0f6b5c; --brand-ink: #ffffff; --brand-soft: #e3f1ee; --danger: #b42318; --danger-soft: #fdecea;
            --warn: #8a5a00; --warn-soft: #fff4dc; --radius: 12px;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #151614; --surface: #1f201e; --text: #f0efea; --muted: #a3a29c; --line: #34352f;
                    --brand: #4fc1a9; --brand-ink: #0c1c18; --brand-soft: #1d3530; --danger: #f97066; --danger-soft: #3a1d1a;
                    --warn: #f5c26b; --warn-soft: #3a2f17; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        a { color: var(--brand); }
        header.top { background: var(--surface); border-bottom: 1px solid var(--line); }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 64px; }
        .brand { font-weight: 700; font-size: 1.15rem; text-decoration: none; color: var(--text); }
        .brand span { color: var(--brand); }
        nav { display: flex; gap: 16px; align-items: center; }
        nav a { text-decoration: none; color: var(--text); }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 16px; }
        main { padding: 32px 0 64px; }
        h1 { font-size: 1.8rem; line-height: 1.2; margin: 0 0 8px; }
        h2 { font-size: 1.2rem; margin: 0 0 16px; }
        .muted { color: var(--muted); }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 24px; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        .stack > * + * { margin-top: 16px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .95rem; }
        input[type=text], input[type=email], input[type=password], input[type=date], input[type=number], select, textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit;
            background: var(--surface); color: var(--text);
        }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--brand); outline-offset: 1px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 18px; border-radius: 8px;
               border: 1px solid var(--brand); background: var(--brand); color: var(--brand-ink); font: inherit; font-weight: 600;
               cursor: pointer; text-decoration: none; }
        .btn.secondary { background: transparent; color: var(--brand); }
        .btn.danger { background: transparent; color: var(--danger); border-color: var(--danger); }
        .btn.small { padding: 6px 12px; font-size: .9rem; }
        .chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .chips label { margin: 0; font-weight: 500; }
        .chips input { position: absolute; opacity: 0; pointer-events: none; }
        .chips span { display: inline-block; padding: 8px 14px; border: 1px solid var(--line); border-radius: 999px; cursor: pointer; background: var(--surface); }
        .chips input:checked + span { border-color: var(--brand); background: var(--brand-soft); color: var(--text); font-weight: 600; }
        .chips input:focus-visible + span { outline: 2px solid var(--brand); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert.ok { background: var(--brand-soft); }
        .alert.err { background: var(--danger-soft); color: var(--danger); }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; background: var(--line); }
        .badge.paid, .badge.active, .badge.completed { background: var(--brand-soft); color: var(--brand); }
        .badge.failed, .badge.past_due, .badge.charged_back { background: var(--danger-soft); color: var(--danger); }
        .badge.processing, .badge.pending, .badge.paused { background: var(--warn-soft); color: var(--warn); }
        .progress { height: 8px; background: var(--line); border-radius: 999px; overflow: hidden; }
        .progress > div { height: 100%; background: var(--brand); }
        table { width: 100%; border-collapse: collapse; font-size: .95rem; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: .85rem; }
        .table-scroll { overflow-x: auto; }
        .stat { font-size: 1.6rem; font-weight: 700; }
        .fineprint { font-size: .82rem; color: var(--muted); }
        details summary { cursor: pointer; color: var(--brand); }
        [hidden] { display: none !important; }
        .admin-nav { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
        .admin-nav a { padding: 6px 12px; border-radius: 8px; text-decoration: none; color: var(--text); font-size: .95rem; }
        .admin-nav a.on { background: var(--brand-soft); color: var(--brand); font-weight: 600; }
        .admin-nav .sep { flex: 1; }
        .kv { display: grid; grid-template-columns: minmax(180px, 280px) 1fr; gap: 10px 16px; align-items: center; }
        @media (max-width: 640px) { .kv { grid-template-columns: 1fr; } }
        .src { font-size: .78rem; color: var(--muted); }
        code.secret { font-family: ui-monospace, monospace; letter-spacing: .05em; }
        .pagination { display: flex; gap: 4px; list-style: none; padding: 0; margin: 0; flex-wrap: wrap; }
        .pagination li > * { display: inline-block; padding: 4px 10px; border: 1px solid var(--line); border-radius: 6px; text-decoration: none; }
        .pagination li.active > * { background: var(--brand); color: var(--brand-ink); border-color: var(--brand); }
        .pagination li.disabled > * { color: var(--muted); }
        @media (max-width: 640px) { h1 { font-size: 1.45rem; } .card { padding: 18px; } nav { gap: 10px; font-size: .95rem; } }
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ route('home') }}">Fund<span>Go</span></a>
        <nav>
            <a href="{{ route('home') }}">Campagnes</a>
            @auth
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}"><strong>Administration</strong></a>
                @endif
                <a href="{{ route('dashboard') }}">Mon espace</a>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn small secondary" type="submit">Déconnexion</button></form>
            @else
                <a href="{{ route('login') }}">Connexion</a>
                <a class="btn small" href="{{ route('register') }}">Créer un compte</a>
            @endauth
        </nav>
    </div>
</header>
<main>
    <div class="wrap">
        @if (session('status'))
            <div class="alert ok" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert err" role="alert">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
        @yield('content')
    </div>
</main>
</body>
</html>
