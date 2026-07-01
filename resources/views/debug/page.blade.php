@php
    /** @var array<string, mixed> $model */
    $exceptions = $model['exceptions'] ?? [];
    $primary = $exceptions[0] ?? ['class' => 'Exception', 'message' => '', 'frames' => []];
    $meta = $model['meta'] ?? [];
    $lookoutUrl = $meta['lookout_url'] ?? null;
    $reference = $meta['reference'] ?? null;
    $appName = $meta['app_name'] ?? config('app.name', 'App');

    $appBase = defined('LARAVEL_START') ? base_path() : ($meta['base_path'] ?? '');
    $short = function (string $file) use ($appBase): string {
        if ($appBase !== '' && str_starts_with($file, $appBase)) {
            return ltrim(substr($file, strlen($appBase)), '/\\');
        }
        return $file;
    };
    $isVendor = fn (string $file): bool => str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $primary['class'] }} — Lookout</title>
    <style>
        :root {
            --bg:#0f1115; --panel:#171a21; --panel2:#1d212b; --line:#262c38;
            --text:#e6e9ef; --muted:#8b93a7; --dim:#5b6376; --accent:#f0506e;
            --accent-soft:#3a1f2a; --amber:#f5b14c; --amber-soft:#3a2f1a;
            --code:#c9d1e4; --link:#6ea8fe; --ok:#4cc38a;
        }
        * { box-sizing:border-box; }
        html,body { margin:0; padding:0; background:var(--bg); color:var(--text);
            font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        code,pre,.mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace; }
        a { color:var(--link); text-decoration:none; } a:hover { text-decoration:underline; }
        .wrap { max-width:1200px; margin:0 auto; padding:24px 20px 80px; }
        .top { border-left:4px solid var(--accent); background:linear-gradient(90deg,var(--accent-soft),transparent 60%);
            padding:16px 18px; border-radius:8px; margin-bottom:18px; }
        .eyebrow { display:flex; gap:10px; align-items:center; flex-wrap:wrap; color:var(--muted); font-size:12px; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; background:var(--panel2);
            border:1px solid var(--line); color:var(--muted); font-size:11px; letter-spacing:.02em; }
        .badge.env { color:var(--amber); border-color:var(--amber-soft); }
        .eclass { margin:8px 0 4px; font-size:15px; color:var(--accent); font-weight:600; }
        .emsg { margin:0; font-size:20px; font-weight:600; color:var(--text); word-break:break-word; }
        .meta-row { margin-top:12px; display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--muted); }
        .meta-row .k { color:var(--dim); }
        .cta { margin-left:auto; }
        .cta a { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:6px;
            background:var(--accent); color:#fff; font-weight:600; font-size:12px; }
        .cta a:hover { text-decoration:none; filter:brightness(1.08); }
        .tabs { display:flex; gap:6px; margin:0 0 12px; flex-wrap:wrap; }
        .tab { padding:5px 12px; border-radius:6px; border:1px solid var(--line); background:var(--panel);
            color:var(--muted); cursor:pointer; font-size:12px; }
        .tab[aria-selected="true"] { background:var(--accent-soft); border-color:var(--accent); color:var(--text); }
        .grid { display:grid; grid-template-columns:340px 1fr; gap:14px; align-items:start; }
        @media (max-width:820px){ .grid{ grid-template-columns:1fr; } }
        .frames { background:var(--panel); border:1px solid var(--line); border-radius:8px; overflow:hidden;
            max-height:520px; overflow-y:auto; }
        .frame { display:block; width:100%; text-align:left; background:none; border:0; border-bottom:1px solid var(--line);
            padding:9px 12px; cursor:pointer; color:var(--text); }
        .frame:hover { background:var(--panel2); }
        .frame[aria-selected="true"] { background:var(--accent-soft); box-shadow:inset 3px 0 0 var(--accent); }
        .frame .fn { font-size:12px; color:var(--code); word-break:break-all; }
        .frame .loc { font-size:11px; color:var(--muted); margin-top:2px; word-break:break-all; }
        .frame.vendor .fn { color:var(--muted); }
        .frame .tag { float:right; font-size:10px; color:var(--dim); }
        .source { background:var(--panel); border:1px solid var(--line); border-radius:8px; overflow:hidden; }
        .source .hd { padding:8px 12px; border-bottom:1px solid var(--line); color:var(--muted); font-size:12px;
            display:flex; justify-content:space-between; gap:12px; }
        .code { margin:0; overflow-x:auto; }
        .code table { border-collapse:collapse; width:100%; }
        .code td { padding:0 12px; white-space:pre; font-size:12.5px; color:var(--code); }
        .code td.ln { text-align:right; color:var(--dim); user-select:none; width:1%; border-right:1px solid var(--line);
            background:var(--panel2); }
        .code tr.hl td { background:var(--amber-soft); }
        .code tr.hl td.ln { color:var(--amber); }
        .source .empty { padding:22px 14px; color:var(--muted); font-size:12px; }
        .panel { display:none; }
        .panel.on { display:block; }
        details { background:var(--panel); border:1px solid var(--line); border-radius:8px; margin-top:12px; }
        summary { padding:10px 14px; cursor:pointer; color:var(--text); font-weight:600; font-size:13px; }
        .kv { padding:4px 14px 14px; }
        .kv .row { display:flex; gap:12px; padding:4px 0; border-top:1px solid var(--line); }
        .kv .row:first-child { border-top:0; }
        .kv .key { color:var(--muted); min-width:180px; font-size:12px; }
        .kv .val { color:var(--code); font-size:12px; word-break:break-word; }
        .crumbs { padding:6px 14px 14px; }
        .crumb { display:flex; gap:10px; padding:6px 0; border-top:1px solid var(--line); font-size:12px; }
        .crumb:first-child { border-top:0; }
        .crumb .lvl { min-width:56px; color:var(--dim); text-transform:uppercase; font-size:10px; padding-top:2px; }
        .crumb .lvl.error { color:var(--accent); } .crumb .lvl.warning { color:var(--amber); }
        .crumb .cat { color:var(--muted); min-width:120px; }
        .crumb .msg { color:var(--code); word-break:break-word; }
        .foot { margin-top:26px; color:var(--dim); font-size:11px; text-align:center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="eyebrow">
            <span class="badge env">{{ $model['environment'] ?: 'unknown' }}</span>
            <span class="badge">{{ $appName }}</span>
            @if ($model['url'])<span>{{ $model['url'] }}</span>@endif
            @if ($lookoutUrl)
                <span class="cta"><a href="{{ $lookoutUrl }}" target="_blank" rel="noopener">View in Lookout ↗</a></span>
            @endif
        </div>
        <div class="eclass">{{ $primary['class'] }}</div>
        <h1 class="emsg">{{ $primary['message'] ?: '(no message)' }}</h1>
        <div class="meta-row">
            @if ($primary['file'])<span><span class="k">at</span> {{ $short($primary['file']) }}:{{ $primary['line'] }}</span>@endif
            @if ($reference)<span><span class="k">ref</span> <code>{{ $reference }}</code></span>@endif
            @if ($model['release'])<span><span class="k">release</span> {{ $model['release'] }}</span>@endif
            @if ($model['commit_sha'])<span><span class="k">commit</span> {{ substr($model['commit_sha'], 0, 8) }}</span>@endif
        </div>
    </div>

    @if (count($exceptions) > 1)
        <div class="tabs" role="tablist" aria-label="Exception chain">
            @foreach ($exceptions as $i => $ex)
                <button class="tab" role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                        data-ex="{{ $i }}" onclick="lkSelectException({{ $i }})">
                    {{ $i === 0 ? 'Thrown' : 'Previous' }}: {{ class_basename($ex['class']) }}
                </button>
            @endforeach
        </div>
    @endif

    @foreach ($exceptions as $i => $ex)
        <div class="ex-block" data-ex="{{ $i }}" @if ($i !== 0) style="display:none" @endif>
            @if ($i !== 0)
                <div class="eclass" style="margin-top:4px">{{ $ex['class'] }}</div>
                <h2 class="emsg" style="font-size:16px">{{ $ex['message'] ?: '(no message)' }}</h2>
            @endif
            <div class="grid">
                <div class="frames" role="tablist">
                    @forelse ($ex['frames'] as $fi => $frame)
                        @php $hasSrc = isset($frame['context_line']); @endphp
                        <button class="frame {{ ($isVendor($frame['file'] ?? '')) ? 'vendor' : '' }}"
                                role="tab" aria-selected="{{ $fi === 0 ? 'true' : 'false' }}"
                                data-ex="{{ $i }}" data-frame="{{ $fi }}"
                                onclick="lkSelectFrame({{ $i }},{{ $fi }})">
                            @if ($isVendor($frame['file'] ?? ''))<span class="tag">vendor</span>@endif
                            <div class="fn">{{ ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '') ?: '{main}' }}</div>
                            <div class="loc">{{ $short($frame['file'] ?? '[internal]') }}@if (isset($frame['line'])):{{ $frame['line'] }}@endif</div>
                        </button>
                    @empty
                        <div class="source empty">No stack frames.</div>
                    @endforelse
                </div>

                <div class="source">
                    @foreach ($ex['frames'] as $fi => $frame)
                        <div class="panel {{ $fi === 0 ? 'on' : '' }}" data-ex="{{ $i }}" data-frame="{{ $fi }}">
                            <div class="hd">
                                <span>{{ $short($frame['file'] ?? '[internal]') }}@if (isset($frame['line'])):{{ $frame['line'] }}@endif</span>
                            </div>
                            @if (isset($frame['context_line']))
                                @php
                                    $startLine = (int) ($frame['context_start_line'] ?? 1);
                                    $pre = $frame['pre_context'] ?? [];
                                    $post = $frame['post_context'] ?? [];
                                    $ln = $startLine;
                                @endphp
                                <pre class="code"><table>
                                    @foreach ($pre as $l)<tr><td class="ln">{{ $ln }}</td><td>{{ $l === '' ? ' ' : $l }}</td></tr>@php $ln++; @endphp
@endforeach<tr class="hl"><td class="ln">{{ $ln }}</td><td>{{ $frame['context_line'] === '' ? ' ' : $frame['context_line'] }}</td></tr>@php $ln++; @endphp
@foreach ($post as $l)<tr><td class="ln">{{ $ln }}</td><td>{{ $l === '' ? ' ' : $l }}</td></tr>@php $ln++; @endphp
@endforeach</table></pre>
                            @else
                                <div class="empty">Source not available on this host for this frame.</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    @if (!empty($model['breadcrumbs']))
        <details open>
            <summary>Breadcrumbs ({{ count($model['breadcrumbs']) }})</summary>
            <div class="crumbs">
                @foreach (array_reverse($model['breadcrumbs']) as $c)
                    @php $lvl = strtolower((string) ($c['level'] ?? 'info')); @endphp
                    <div class="crumb">
                        <span class="lvl {{ $lvl }}">{{ $lvl }}</span>
                        <span class="cat">{{ $c['category'] ?? ($c['type'] ?? '') }}</span>
                        <span class="msg">{{ is_string($c['message'] ?? null) ? $c['message'] : json_encode($c['message'] ?? '') }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if ($model['user'])
        <details>
            <summary>User</summary>
            <div class="kv">
                @foreach ($model['user'] as $k => $v)
                    <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ is_scalar($v) ? $v : json_encode($v) }}</span></div>
                @endforeach
            </div>
        </details>
    @endif

    @if (!empty($model['context']))
        <details>
            <summary>Context</summary>
            <div class="kv">
                @foreach ($model['context'] as $k => $v)
                    <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ is_scalar($v) ? $v : json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</span></div>
                @endforeach
            </div>
        </details>
    @endif

    <div class="foot">Rendered locally by Lookout · source snippets never leave this machine</div>
</div>
<script>
    function lkSelectException(i){
        document.querySelectorAll('.tab').forEach(function(t){ t.setAttribute('aria-selected', String(Number(t.dataset.ex)===i)); });
        document.querySelectorAll('.ex-block').forEach(function(b){ b.style.display = (Number(b.dataset.ex)===i)?'':'none'; });
    }
    function lkSelectFrame(ex, fi){
        document.querySelectorAll('.frame[data-ex="'+ex+'"]').forEach(function(f){
            f.setAttribute('aria-selected', String(Number(f.dataset.frame)===fi));
        });
        document.querySelectorAll('.panel[data-ex="'+ex+'"]').forEach(function(p){
            p.classList.toggle('on', Number(p.dataset.frame)===fi);
        });
    }
</script>
</body>
</html>
