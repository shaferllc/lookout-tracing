@php /** @var list<array{id: string, saved_at: int, class: string, message: string, url: string}> $entries */ @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Local errors — Lookout</title>
    <style>
        :root { --bg:#0f1115; --panel:#171a21; --line:#262c38; --text:#e6e9ef; --muted:#8b93a7;
            --dim:#5b6376; --accent:#f0506e; --link:#6ea8fe; }
        html,body { margin:0; background:var(--bg); color:var(--text);
            font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width:960px; margin:0 auto; padding:32px 20px 80px; }
        h1 { font-size:18px; margin:0 0 4px; }
        .sub { color:var(--muted); font-size:12px; margin-bottom:20px; }
        a { color:var(--link); text-decoration:none; } a:hover { text-decoration:underline; }
        .entry { display:block; background:var(--panel); border:1px solid var(--line); border-radius:8px;
            padding:12px 16px; margin-bottom:10px; color:var(--text); }
        .entry:hover { border-color:var(--dim); text-decoration:none; }
        .entry .cls { color:var(--accent); font-size:12px; font-weight:600; }
        .entry .msg { font-weight:600; margin:2px 0; word-break:break-word; }
        .entry .meta { color:var(--dim); font-size:11px; }
        .empty { color:var(--muted); padding:40px 0; text-align:center; }
        .foot { margin-top:26px; color:var(--dim); font-size:11px; text-align:center; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Recent local errors — {{ $appName }}</h1>
    <div class="sub">Debug pages rendered on this machine (newest first, last {{ count($entries) }})</div>
    @forelse ($entries as $entry)
        <a class="entry" href="/_lookout/errors/{{ $entry['id'] }}">
            <div class="cls">{{ $entry['class'] }}</div>
            <div class="msg">{{ $entry['message'] !== '' ? $entry['message'] : '(no message)' }}</div>
            <div class="meta">{{ date('Y-m-d H:i:s', $entry['saved_at']) }}@if ($entry['url'] !== '') · {{ $entry['url'] }}@endif</div>
        </a>
    @empty
        <div class="empty">No locally-rendered errors yet.</div>
    @endforelse
    <div class="foot">Rendered locally by Lookout · stored under storage/framework/lookout/errors</div>
</div>
</body>
</html>
