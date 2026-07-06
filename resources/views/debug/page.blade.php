@php
    /** @var array<string, mixed> $model */
    $exceptions = $model['exceptions'] ?? [];
    $primary = $exceptions[0] ?? ['class' => 'Exception', 'message' => '', 'frames' => []];
    $meta = $model['meta'] ?? [];
    $lookoutUrl = $model['lookout_url'] ?? ($meta['lookout_url'] ?? null);
    $reference = $meta['reference'] ?? null;
    $appName = $meta['app_name'] ?? config('app.name', 'App');
    $runtime = $model['runtime'] ?? [];
    $request = $model['request'] ?? [];
    $queries = $model['queries'] ?? [];

    $appBase = defined('LARAVEL_START') ? base_path() : ($meta['base_path'] ?? '');
    $short = function (string $file) use ($appBase): string {
        if ($appBase !== '' && str_starts_with($file, $appBase)) {
            return ltrim(substr($file, strlen($appBase)), '/\\');
        }
        return $file;
    };
    $isVendor = fn (string $file): bool => str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');

    $kvRows = function (array $data): array {
        $rows = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $rows[$k] = $v ? 'true' : 'false';
            } elseif (is_scalar($v) || $v === null) {
                $rows[$k] = (string) $v;
            } else {
                $rows[$k] = (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        return $rows;
    };
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
            --code:#c9d1e4; --link:#6ea8fe; --ok:#4cc38a; --ok-soft:#173226;
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
        .badge.lvl { color:var(--accent); border-color:var(--accent-soft); text-transform:uppercase; }
        .badge.handled { color:var(--ok); border-color:var(--ok-soft); }
        .eclass { margin:8px 0 4px; font-size:15px; color:var(--accent); font-weight:600; }
        .emsg { margin:0; font-size:20px; font-weight:600; color:var(--text); word-break:break-word; }
        .meta-row { margin-top:12px; display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--muted); }
        .meta-row .k { color:var(--dim); }
        .actions { margin-top:14px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:6px;
            border:1px solid var(--line); background:var(--panel2); color:var(--text); font-weight:600;
            font-size:12px; cursor:pointer; }
        .btn:hover { border-color:var(--dim); }
        .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        .btn.primary:hover { filter:brightness(1.08); }
        .btn.copied { background:var(--ok-soft); border-color:var(--ok); color:var(--ok); }
        .menu { position:relative; display:inline-flex; }
        .menu.open > .btn { border-color:var(--dim); }
        .menu-panel { position:absolute; top:calc(100% + 6px); left:0; z-index:50; min-width:200px;
            background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:5px;
            box-shadow:0 10px 30px rgba(0,0,0,.45); }
        .menu-item { display:block; width:100%; text-align:left; background:none; border:0; border-radius:5px;
            padding:7px 10px; color:var(--text); font-size:12px; cursor:pointer; }
        .menu-item:hover { background:var(--panel2); text-decoration:none; }
        .menu-item.copied { color:var(--ok); }
        .menu-sep { height:1px; background:var(--line); margin:5px 4px; }
        .solution { border-left:4px solid var(--ok); background:linear-gradient(90deg,var(--ok-soft),transparent 70%);
            padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:13px; }
        .stats-banner { display:flex; align-items:center; gap:4px; flex-wrap:wrap; border:1px solid var(--amber-soft);
            border-left:4px solid var(--amber); background:linear-gradient(90deg,var(--amber-soft),transparent 70%);
            padding:10px 16px; border-radius:8px; margin-bottom:18px; font-size:12.5px; color:var(--muted); }
        .stats-banner strong { color:var(--amber); }
        .blame { margin-top:6px; }
        .blame code { color:var(--dim); }
        .frame-search { flex:1; min-width:50px; background:var(--bg); color:var(--text); border:1px solid var(--line);
            border-radius:5px; padding:3px 8px; font-size:11px; }
        .frame-search::placeholder { color:var(--dim); }
        .frame-search:focus { outline:none; border-color:var(--dim); }
        .lk-editor { appearance:none; -webkit-appearance:none; background:var(--bg)
            url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="8" height="5" viewBox="0 0 8 5"><path d="M0 0l4 5 4-5z" fill="%235b6376"/></svg>')
            no-repeat right 7px center; color:var(--muted); border:1px solid var(--line); border-radius:5px;
            padding:3px 20px 3px 8px; font-size:11px; cursor:pointer; }
        .lk-editor:focus { outline:none; border-color:var(--dim); }
        .frame.filtered { display:none; }
        .timeline { padding:8px 14px 14px; }
        .timeline .t-row { display:grid; grid-template-columns:220px 1fr 64px; gap:10px; align-items:center;
            padding:3px 0; border-top:1px solid var(--line); font-size:11px; }
        .timeline .t-row:first-child { border-top:0; }
        .timeline .t-label { color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .timeline .t-label .op { color:var(--link); margin-right:6px; }
        .timeline .t-track { position:relative; height:10px; background:var(--panel2); border-radius:3px; }
        .timeline .t-bar { position:absolute; top:1px; bottom:1px; border-radius:2px; background:var(--link); min-width:2px; }
        .timeline .t-bar.err { background:var(--accent); }
        .timeline .t-ms { text-align:right; color:var(--dim); }
        .dumps { padding:6px 14px 14px; }
        .dump { padding:8px 0; border-top:1px solid var(--line); font-size:12px; }
        .dump:first-child { border-top:0; }
        .dump .d-label { color:var(--amber); font-size:11px; margin-bottom:2px; }
        .dump .d-preview { color:var(--code); font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
            word-break:break-word; white-space:pre-wrap; }
        .tabs { display:flex; gap:6px; margin:0 0 12px; flex-wrap:wrap; }
        .tab { padding:5px 12px; border-radius:6px; border:1px solid var(--line); background:var(--panel);
            color:var(--muted); cursor:pointer; font-size:12px; }
        .tab[aria-selected="true"] { background:var(--accent-soft); border-color:var(--accent); color:var(--text); }
        .grid { display:grid; grid-template-columns:340px 1fr; gap:14px; align-items:start; }
        @media (max-width:820px){ .grid{ grid-template-columns:1fr; } }
        .frames-head { display:flex; align-items:center; gap:10px; padding:7px 10px;
            background:var(--panel2); border:1px solid var(--line); border-bottom:0;
            border-radius:8px 8px 0 0; font-size:11px; color:var(--muted); }
        .frames-head .fcount { white-space:nowrap; color:var(--dim); }
        .frames-head label { display:flex; gap:5px; align-items:center; cursor:pointer; white-space:nowrap; }
        .frames-head input[type="checkbox"] { width:12px; height:12px; margin:0; accent-color:var(--accent); }
        .frames { background:var(--panel); border:1px solid var(--line); border-radius:0 0 8px 8px; overflow:hidden;
            max-height:520px; overflow-y:auto; }
        .frame { display:block; width:100%; text-align:left; background:none; border:0; border-bottom:1px solid var(--line);
            padding:9px 12px; cursor:pointer; color:var(--text); }
        .frame:hover { background:var(--panel2); }
        .frame[aria-selected="true"] { background:var(--accent-soft); box-shadow:inset 3px 0 0 var(--accent); }
        .frame .fn { font-size:12px; color:var(--code); word-break:break-all; }
        .frame .loc { font-size:11px; color:var(--muted); margin-top:2px; word-break:break-all; }
        .frame.vendor .fn { color:var(--muted); }
        .frame .tag { float:right; font-size:10px; color:var(--dim); }
        .hide-vendor .frame.vendor { display:none; }
        .source { background:var(--panel); border:1px solid var(--line); border-radius:8px; overflow:hidden; }
        .source .hd { padding:8px 12px; border-bottom:1px solid var(--line); color:var(--muted); font-size:12px;
            display:flex; justify-content:space-between; gap:12px; align-items:center; }
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
        summary .count { color:var(--dim); font-weight:400; }
        .kv { padding:4px 14px 14px; }
        .kv .row { display:flex; gap:12px; padding:4px 0; border-top:1px solid var(--line); }
        .kv .row:first-child { border-top:0; }
        .kv .key { color:var(--muted); min-width:180px; font-size:12px; word-break:break-all; }
        .kv .val { color:var(--code); font-size:12px; word-break:break-word; white-space:pre-wrap; }
        .kv h4 { margin:14px 0 4px; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--dim); }
        .crumbs { padding:6px 14px 14px; }
        .crumb { display:flex; gap:10px; padding:6px 0; border-top:1px solid var(--line); font-size:12px; }
        .crumb:first-child { border-top:0; }
        .crumb .lvl { min-width:56px; color:var(--dim); text-transform:uppercase; font-size:10px; padding-top:2px; }
        .crumb .lvl.error { color:var(--accent); } .crumb .lvl.warning { color:var(--amber); }
        .crumb .cat { color:var(--muted); min-width:120px; }
        .crumb .msg { color:var(--code); word-break:break-word; }
        .sql { padding:6px 14px 14px; }
        .sql .q { padding:8px 0; border-top:1px solid var(--line); font-size:12px; color:var(--code);
            font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; word-break:break-word; }
        .sql .q:first-child { border-top:0; }
        .foot { margin-top:26px; color:var(--dim); font-size:11px; text-align:center; }
        .edit-link { font-size:11px; white-space:nowrap; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="eyebrow">
            <span class="badge env">{{ $model['environment'] ?: 'unknown' }}</span>
            <span class="badge">{{ $appName }}</span>
            <span class="badge lvl">{{ $model['level'] ?? 'error' }}</span>
            @if (!empty($model['handled']))<span class="badge handled">handled</span>@endif
            @if ($model['url'])<span>{{ $model['url'] }}</span>@endif
        </div>
        <div class="eclass">{{ $primary['class'] }}</div>
        <h1 class="emsg">{{ $primary['message'] ?: '(no message)' }}</h1>
        <div class="meta-row">
            @if ($primary['file'])
                <span><span class="k">at</span>
                    @if (!empty($primary['editor_href']))
                        <a href="{{ $primary['editor_href'] }}">{{ $short($primary['file']) }}:{{ $primary['line'] }}</a>
                    @else
                        {{ $short($primary['file']) }}:{{ $primary['line'] }}
                    @endif
                </span>
            @endif
            @if ($reference)<span><span class="k">ref</span> <code>{{ $reference }}</code></span>@endif
            @if (!empty($model['occurrence_uuid']))<span><span class="k">occurrence</span> <code>{{ $model['occurrence_uuid'] }}</code></span>@endif
            @if (!empty($model['trace_id']))<span><span class="k">trace</span> <code>{{ $model['trace_id'] }}</code></span>@endif
            @if (!empty($model['transaction']))<span><span class="k">txn</span> {{ $model['transaction'] }}</span>@endif
            @if ($model['release'])<span><span class="k">release</span> {{ $model['release'] }}</span>@endif
            @if ($model['commit_sha'])<span><span class="k">commit</span> {{ substr($model['commit_sha'], 0, 8) }}</span>@endif
            @if (!empty($runtime['php_version']))<span><span class="k">php</span> {{ $runtime['php_version'] }}</span>@endif
            @if (!empty($runtime['framework_version']))<span><span class="k">laravel</span> {{ $runtime['framework_version'] }}</span>@endif
            @if (!empty($runtime['peak_memory']))<span><span class="k">peak mem</span> {{ $runtime['peak_memory'] }}</span>@endif
            @if (!empty($runtime['elapsed']))<span><span class="k">elapsed</span> {{ $runtime['elapsed'] }}</span>@endif
            @if (!empty($runtime['rendered_at']))<span><span class="k">at</span> {{ $runtime['rendered_at'] }}</span>@endif
        </div>
        @if (!empty($model['blame']))
            <div class="meta-row blame">
                <span><span class="k">last touched by</span> {{ $model['blame']['author'] }}
                    @if (!empty($model['blame']['age'])) · {{ $model['blame']['age'] }}@endif
                    @if (!empty($model['blame']['summary'])) · “{{ $model['blame']['summary'] }}”@endif
                    @if (!empty($model['blame']['sha'])) <code>{{ $model['blame']['sha'] }}</code>@endif
                </span>
            </div>
        @endif
        <div class="actions">
            <button class="btn primary" type="button" data-copy="markdown">Copy as Markdown</button>
            <button class="btn" type="button" data-copy="ai">Copy AI prompt</button>
            @if ($lookoutUrl)<a class="btn" href="{{ $lookoutUrl }}" target="_blank" rel="noopener">View in Lookout ↗</a>@endif
            <span class="menu" id="lk-more-menu">
                <button class="btn" type="button" id="lk-more-btn" aria-haspopup="true" aria-expanded="false">More ▾</button>
                <div class="menu-panel" hidden>
                    @if (!empty($model['curl']))<button class="menu-item" type="button" data-copy="curl">Copy cURL</button>@endif
                    @if (!empty($model['test_skeleton']))<button class="menu-item" type="button" data-copy="test">Copy failing test</button>@endif
                    @if (!empty($model['stack_text']))<button class="menu-item" type="button" data-copy="stack">Copy stack trace</button>@endif
                    <button class="menu-item" type="button" id="lk-download">Download .html</button>
                    @if (!empty($model['share_url']) || !empty($model['ignore_url']))
                        <div class="menu-sep"></div>
                        @if (!empty($model['share_url']))<a class="menu-item" href="{{ $model['share_url'] }}" target="_blank" rel="noopener">Share ↗</a>@endif
                        @if (!empty($model['ignore_url']))<a class="menu-item" href="{{ $model['ignore_url'] }}" target="_blank" rel="noopener">Ignore ↗</a>@endif
                    @endif
                    @if (!empty($model['search_links']))
                        <div class="menu-sep"></div>
                        @foreach ($model['search_links'] as $link)
                            <a class="menu-item" href="{{ $link['url'] }}" target="_blank" rel="noopener">Search {{ $link['label'] }} ↗</a>
                        @endforeach
                    @endif
                </div>
            </span>
        </div>
    </div>

    @if (!empty($model['health']))
        <div class="stats-banner" style="border-left-color:var(--accent); border-color:var(--accent-soft); background:linear-gradient(90deg,var(--accent-soft),transparent 70%); display:block">
            <strong style="color:var(--accent)">Environment warnings</strong>
            @foreach ($model['health'] as $check)
                <div style="margin-top:4px">· {{ $check['message'] }}</div>
            @endforeach
        </div>
    @endif

    @if (!empty($model['stats']))
        @php $stats = $model['stats']; @endphp
        <div class="stats-banner" style="display:block">
            <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">
                <strong>Seen {{ number_format((int) ($stats['count'] ?? 0)) }}×</strong>
                @if (!empty($stats['first_seen_at']))<span> · first seen {{ $stats['first_seen_at'] }}</span>@endif
                @if (!empty($stats['last_seen_at']))<span> · last seen {{ $stats['last_seen_at'] }}</span>@endif
                @if (!empty($stats['status']))<span class="badge lvl" style="margin-left:6px">{{ $stats['status'] }}</span>@endif
                @if (!empty($stats['url']))<a href="{{ $stats['url'] }}" target="_blank" rel="noopener" style="margin-left:auto">Open issue ↗</a>@endif
            </div>
            @php
                $deploy = is_array($stats['latest_deploy'] ?? null) ? $stats['latest_deploy'] : null;
                $firstSeen = $stats['first_seen_at'] ?? null;
                $afterDeploy = $deploy && !empty($deploy['deployed_at']) && is_string($firstSeen) && $firstSeen >= $deploy['deployed_at'];
            @endphp
            @if ($deploy && !empty($deploy['deployed_at']))
                <div style="margin-top:4px">
                    · latest deploy @if (!empty($deploy['commit_sha']))<code>{{ substr($deploy['commit_sha'], 0, 8) }}</code>@endif
                    @if (!empty($deploy['release'])) ({{ $deploy['release'] }})@endif at {{ $deploy['deployed_at'] }}
                    @if ($afterDeploy)<strong> — first seen after this deploy</strong>@endif
                </div>
            @endif
            @php $note = is_array($stats['resolution_note'] ?? null) ? $stats['resolution_note'] : null; @endphp
            @if ($note)
                <div style="margin-top:4px">
                    · team note: “{{ $note['body'] }}”@if (!empty($note['author'])) — {{ $note['author'] }}@endif
                    @if (!empty($note['created_at'])) <span style="color:var(--dim)">({{ $note['created_at'] }})</span>@endif
                </div>
            @endif
        </div>
    @endif

    @if (!empty($model['solution']) || !empty($model['action']))
        <div class="solution">
            @if (!empty($model['solution']))<strong>Possible solution:</strong> {{ $model['solution'] }}@endif
            @if (!empty($model['action']))
                <div style="margin-top:8px">
                    <button class="btn" type="button" id="lk-action"
                            data-action="{{ $model['action']['id'] }}" data-token="{{ $model['action']['token'] }}">
                        ▶ {{ $model['action']['label'] }}
                    </button>
                    <pre id="lk-action-output" style="display:none;margin:10px 0 0;padding:10px;background:var(--bg);border:1px solid var(--line);border-radius:6px;font-size:11.5px;white-space:pre-wrap"></pre>
                </div>
            @endif
        </div>
    @endif

    @if (!empty($model['warnings']))
        <details open>
            <summary>Earlier warnings this request <span class="count">({{ count($model['warnings']) }})</span></summary>
            <div class="crumbs">
                @foreach ($model['warnings'] as $c)
                    @php $lvl = strtolower((string) ($c['level'] ?? 'warning')); @endphp
                    <div class="crumb">
                        <span class="lvl {{ $lvl }}">{{ $lvl }}</span>
                        <span class="cat">{{ $c['category'] ?? ($c['type'] ?? '') }}</span>
                        <span class="msg">{{ is_string($c['message'] ?? null) ? $c['message'] : json_encode($c['message'] ?? '') }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

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
                <div>
                    <div class="frames-head">
                        <span class="fcount">{{ count($ex['frames']) }} frames</span>
                        <input class="frame-search" type="search" placeholder="filter (j/k to move)" data-ex="{{ $i }}">
                        <label title="Hide vendor frames"><input type="checkbox" class="lk-vendor-toggle" data-ex="{{ $i }}"> hide vendor</label>
                        @if ($i === 0 && !empty($model['editor_id']))
                            <select class="lk-editor" id="lk-editor" title="Editor for open-in-editor links">
                                @foreach (array_keys(\Lookout\Tracing\Debug\EditorLink::templates()) as $editorId)
                                    <option value="{{ $editorId }}" @selected($editorId === $model['editor_id'])>{{ $editorId }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div class="frames" role="tablist" data-ex="{{ $i }}">
                        @forelse ($ex['frames'] as $fi => $frame)
                            <button class="frame {{ ($isVendor($frame['file'] ?? '')) ? 'vendor' : '' }}"
                                    role="tab" aria-selected="{{ $fi === 0 ? 'true' : 'false' }}"
                                    data-ex="{{ $i }}" data-frame="{{ $fi }}"
                                    onclick="lkSelectFrame({{ $i }},{{ $fi }})">
                                @if (!empty($frame['is_blade']))<span class="tag" style="color:var(--amber)">blade</span>
                                @elseif ($isVendor($frame['file'] ?? ''))<span class="tag">vendor</span>@endif
                                <div class="fn">{{ ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '') ?: '{main}' }}</div>
                                <div class="loc">{{ $short($frame['file'] ?? '[internal]') }}@if (isset($frame['line'])):{{ $frame['line'] }}@endif</div>
                            </button>
                        @empty
                            <div class="source empty">No stack frames.</div>
                        @endforelse
                    </div>
                </div>

                <div class="source">
                    @foreach ($ex['frames'] as $fi => $frame)
                        <div class="panel {{ $fi === 0 ? 'on' : '' }}" data-ex="{{ $i }}" data-frame="{{ $fi }}">
                            <div class="hd">
                                <span>{{ $short($frame['file'] ?? '[internal]') }}@if (isset($frame['line'])):{{ $frame['line'] }}@endif
                                    @if (!empty($frame['is_blade']))<span style="color:var(--dim)"> (compiled: …{{ substr((string) ($frame['compiled_file'] ?? ''), -18) }}@if (isset($frame['compiled_line'])):{{ $frame['compiled_line'] }}@endif)</span>@endif
                                </span>
                                @if (!empty($frame['editor_href']))
                                    <a class="edit-link" href="{{ $frame['editor_href'] }}"
                                       data-path="{{ $frame['editor_path'] ?? '' }}" data-line="{{ $frame['line'] ?? 1 }}">Open in editor ↗</a>
                                @endif
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

    @if ($request !== [])
        <details open>
            <summary>Request <span class="count">{{ ($request['method'] ?? '') }} {{ parse_url($request['url'] ?? '', PHP_URL_PATH) ?: '' }}</span></summary>
            <div class="kv">
                @foreach (['method' => 'Method', 'url' => 'URL', 'ip' => 'IP', 'user_agent' => 'User agent', 'referer' => 'Referer'] as $key => $label)
                    @if (!empty($request[$key]))
                        <div class="row"><span class="key">{{ $label }}</span><span class="val">{{ $request[$key] }}</span></div>
                    @endif
                @endforeach

                @if (!empty($request['route']))
                    <h4>Route</h4>
                    @foreach ($kvRows($request['route']) as $k => $v)
                        <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ $v }}</span></div>
                    @endforeach
                @endif
                @foreach (['headers' => 'Headers', 'query' => 'Query string', 'body' => 'Body', 'cookies' => 'Cookies', 'session' => 'Session'] as $key => $label)
                    @if (!empty($request[$key]) && is_array($request[$key]))
                        <h4>{{ $label }}</h4>
                        @foreach ($kvRows($request[$key]) as $k => $v)
                            <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ $v }}</span></div>
                        @endforeach
                    @endif
                @endforeach
                @if (!empty($request['files']))
                    <h4>Uploaded files</h4>
                    <div class="row"><span class="val">{{ implode(', ', $request['files']) }}</span></div>
                @endif
            </div>
        </details>
    @endif

    @if ($queries !== [])
        <details>
            <summary>Queries <span class="count">({{ count($queries) }})</span></summary>
            <div class="sql">
                @foreach ($queries as $q)
                    <div class="q">{{ is_string($q['message'] ?? null) ? $q['message'] : json_encode($q['message'] ?? '') }}</div>
                @endforeach
            </div>
        </details>
    @endif

    @if (!empty($model['timeline']['spans']))
        <details open>
            <summary>Timeline <span class="count">({{ count($model['timeline']['spans']) }} spans · {{ $model['timeline']['total_ms'] }} ms window)</span></summary>
            <div class="timeline">
                @foreach ($model['timeline']['spans'] as $span)
                    <div class="t-row">
                        <span class="t-label"><span class="op">{{ $span['op'] }}</span>{{ $span['description'] }}</span>
                        <span class="t-track"><span class="t-bar {{ ($span['status'] ?? '') === 'error' ? 'err' : '' }}"
                            style="left:{{ $span['offset_pct'] }}%;width:{{ $span['width_pct'] }}%"></span></span>
                        <span class="t-ms">{{ $span['duration_ms'] }} ms</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if (!empty($model['dumps']))
        <details open>
            <summary>Dumps <span class="count">({{ count($model['dumps']) }})</span></summary>
            <div class="dumps">
                @foreach ($model['dumps'] as $dump)
                    <div class="dump">
                        <div class="d-label">{{ $dump['label'] ?? ($dump['root_class'] ?? ($dump['root_type'] ?? 'dump')) }}</div>
                        <div class="d-preview">{{ $dump['preview'] ?? '' }}</div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if (!empty($model['breadcrumbs']))
        <details open>
            <summary>Breadcrumbs <span class="count">({{ count($model['breadcrumbs']) }})</span></summary>
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
                @foreach ($kvRows($model['user']) as $k => $v)
                    <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ $v }}</span></div>
                @endforeach
            </div>
        </details>
    @endif

    @foreach ([
        'laravel' => 'Application',
        'server' => 'Server',
        'git' => 'Git',
        'exception_context' => 'Exception context',
        'log_context' => 'Log context',
        'context' => 'Context',
    ] as $panelKey => $panelTitle)
        @if (!empty($model[$panelKey]) && is_array($model[$panelKey]))
            <details>
                <summary>{{ $panelTitle }} <span class="count">({{ count($model[$panelKey]) }})</span></summary>
                <div class="kv">
                    @foreach ($kvRows($model[$panelKey]) as $k => $v)
                        <div class="row"><span class="key">{{ $k }}</span><span class="val">{{ $v }}</span></div>
                    @endforeach
                </div>
            </details>
        @endif
    @endforeach

    <div class="foot">Rendered locally by Lookout · source snippets never leave this machine
        @if (config('app.debug'))· <a href="/_lookout/errors">recent local errors</a>@endif
    </div>
</div>
<script type="application/json" id="lk-copy-data">{!! json_encode([
    'markdown' => $model['markdown'] ?? '',
    'ai' => $model['ai_prompt'] ?? '',
    'curl' => $model['curl'] ?? '',
    'test' => $model['test_skeleton'] ?? '',
    'stack' => $model['stack_text'] ?? '',
    'editors' => \Lookout\Tracing\Debug\EditorLink::templates(),
    'occurrence' => $model['occurrence_uuid'] ?? '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
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
    (function(){
        var data = {};
        try { data = JSON.parse(document.getElementById('lk-copy-data').textContent || '{}'); } catch (e) {}

        function copyText(text){
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function(resolve, reject){
                var ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy') ? resolve() : reject(); }
                catch (e) { reject(e); }
                finally { document.body.removeChild(ta); }
            });
        }

        document.querySelectorAll('[data-copy]').forEach(function(btn){
            btn.addEventListener('click', function(){
                var text = data[btn.dataset.copy] || '';
                if (!text) { return; }
                copyText(text).then(function(){
                    var original = btn.textContent;
                    btn.textContent = 'Copied ✓';
                    btn.classList.add('copied');
                    setTimeout(function(){ btn.textContent = original; btn.classList.remove('copied'); }, 1600);
                }).catch(function(){
                    btn.textContent = 'Copy failed';
                    setTimeout(function(){ btn.textContent = btn.dataset.copy === 'markdown' ? 'Copy as Markdown' : btn.textContent; }, 1600);
                });
            });
        });

        document.querySelectorAll('.lk-vendor-toggle').forEach(function(cb){
            cb.addEventListener('change', function(){
                var list = document.querySelector('.frames[data-ex="'+cb.dataset.ex+'"]');
                if (list) { list.classList.toggle('hide-vendor', cb.checked); }
            });
        });

        document.querySelectorAll('.frame-search').forEach(function(input){
            input.addEventListener('input', function(){
                var q = input.value.toLowerCase();
                document.querySelectorAll('.frame[data-ex="'+input.dataset.ex+'"]').forEach(function(f){
                    f.classList.toggle('filtered', q !== '' && f.textContent.toLowerCase().indexOf(q) === -1);
                });
            });
        });

        var moreMenu = document.getElementById('lk-more-menu');
        var moreBtn = document.getElementById('lk-more-btn');
        if (moreMenu && moreBtn) {
            var morePanel = moreMenu.querySelector('.menu-panel');
            function setMenu(open){
                morePanel.hidden = !open;
                moreMenu.classList.toggle('open', open);
                moreBtn.setAttribute('aria-expanded', String(open));
            }
            moreBtn.addEventListener('click', function(e){
                e.stopPropagation();
                setMenu(morePanel.hidden);
            });
            document.addEventListener('click', function(e){
                if (!morePanel.hidden && !moreMenu.contains(e.target)) { setMenu(false); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !morePanel.hidden) { setMenu(false); }
            });
        }

        var downloadBtn = document.getElementById('lk-download');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', function(){
                var overlay = document.getElementById('lk-debug-overlay');
                if (overlay) { overlay.remove(); }
                var blob = new Blob(['<!DOCTYPE html>\n' + document.documentElement.outerHTML], {type: 'text/html'});
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'lookout-error-' + (data.occurrence || 'snapshot') + '.html';
                document.body.appendChild(a);
                a.click();
                setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
            });
        }

        var actionBtn = document.getElementById('lk-action');
        if (actionBtn) {
            actionBtn.addEventListener('click', function(){
                actionBtn.disabled = true;
                actionBtn.textContent = 'Running…';
                var out = document.getElementById('lk-action-output');
                fetch('/_lookout/actions', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({action: actionBtn.dataset.action, token: actionBtn.dataset.token})
                }).then(function(r){ return r.json(); }).then(function(res){
                    actionBtn.textContent = res.ok ? '✓ Done — reload to retry' : '✗ Failed';
                    if (out) { out.style.display = 'block'; out.textContent = res.output || ''; }
                }).catch(function(){
                    actionBtn.textContent = '✗ Request failed';
                    actionBtn.disabled = false;
                });
            });
        }

        var editorSelect = document.getElementById('lk-editor');
        function applyEditor(id){
            var tpl = (data.editors || {})[id];
            if (!tpl) { return; }
            document.querySelectorAll('a.edit-link[data-path]').forEach(function(a){
                var file = a.dataset.path;
                if (!file) { return; }
                a.href = tpl
                    .replace('{file}', file.replace(/%/g,'%25').replace(/#/g,'%23').replace(/\?/g,'%3F').replace(/ /g,'%20'))
                    .replace('{line}', a.dataset.line || '1');
            });
        }
        if (editorSelect) {
            try {
                var saved = localStorage.getItem('lk-editor');
                if (saved && (data.editors || {})[saved]) {
                    editorSelect.value = saved;
                    applyEditor(saved);
                }
            } catch (e) {}
            editorSelect.addEventListener('change', function(){
                try { localStorage.setItem('lk-editor', editorSelect.value); } catch (e) {}
                applyEditor(editorSelect.value);
            });
        }

        function visibleFrames(ex){
            return Array.prototype.filter.call(
                document.querySelectorAll('.frame[data-ex="'+ex+'"]'),
                function(f){ return f.offsetParent !== null; }
            );
        }
        document.addEventListener('keydown', function(e){
            if (e.key !== 'j' && e.key !== 'k') { return; }
            var t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) { return; }
            var block = Array.prototype.find.call(document.querySelectorAll('.ex-block'),
                function(b){ return b.style.display !== 'none'; });
            if (!block) { return; }
            var ex = Number(block.dataset.ex);
            var frames = visibleFrames(ex);
            if (!frames.length) { return; }
            var idx = frames.findIndex(function(f){ return f.getAttribute('aria-selected') === 'true'; });
            var next = e.key === 'j' ? Math.min(idx + 1, frames.length - 1) : Math.max(idx - 1, 0);
            if (idx === -1) { next = 0; }
            var frame = frames[next];
            lkSelectFrame(ex, Number(frame.dataset.frame));
            frame.scrollIntoView({block: 'nearest'});
            e.preventDefault();
        });
    })();
</script>
</body>
</html>
