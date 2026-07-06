@php
    /** @var string $path */
    /** @var string $method */
    /** @var list<array{uri: string, score: float}> $suggestions */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Lookout</title>
    <style>
        :root { --bg:#0f1115; --panel:#171a21; --line:#262c38; --text:#e6e9ef; --muted:#8b93a7;
            --dim:#5b6376; --amber:#f5b14c; --amber-soft:#3a2f1a; --link:#6ea8fe; }
        html,body { margin:0; background:var(--bg); color:var(--text);
            font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
        .wrap { max-width:760px; margin:0 auto; padding:48px 20px 80px; }
        .top { border-left:4px solid var(--amber); background:linear-gradient(90deg,var(--amber-soft),transparent 60%);
            padding:16px 18px; border-radius:8px; margin-bottom:22px; }
        .eyebrow { color:var(--amber); font-size:12px; font-weight:600; letter-spacing:.04em; }
        h1 { margin:6px 0 0; font-size:19px; word-break:break-all; }
        .hint { color:var(--muted); font-size:13px; margin:18px 0 8px; }
        a { color:var(--link); text-decoration:none; } a:hover { text-decoration:underline; }
        .sugg { display:block; background:var(--panel); border:1px solid var(--line); border-radius:8px;
            padding:10px 14px; margin-bottom:8px; color:var(--text); font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:13px; }
        .sugg:hover { border-color:var(--dim); text-decoration:none; }
        .none { color:var(--dim); font-size:13px; }
        .foot { margin-top:26px; color:var(--dim); font-size:11px; text-align:center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="eyebrow">404 · {{ $appName }}</div>
        <h1>{{ $method }} <code>{{ $path }}</code> matched no route</h1>
    </div>
    @if ($suggestions !== [])
        <div class="hint">Did you mean:</div>
        @foreach ($suggestions as $s)
            <a class="sugg" href="{{ $s['uri'] }}">{{ $s['uri'] }}</a>
        @endforeach
    @else
        <div class="none">No similar routes registered. Check <code>php artisan route:list</code>.</div>
    @endif
    <div class="foot">Rendered locally by Lookout · shown only to authorized debug-page viewers</div>
</div>
</body>
</html>
