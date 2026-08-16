<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install ApproveDesk — {{ $title ?? 'Setup' }}</title>
    <style>
        :root { --accent: #4f46e5; --bg: #0a0a0b; --card: #141417; --line: #26262b; --text: #f4f4f5; --muted: #a1a1aa; --ok: #22c55e; --bad: #ef4444; --warn: #eab308; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; line-height: 1.55; }
        .shell { width: 100%; max-width: 640px; }
        .brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; font-size: 1.15rem; margin-bottom: 1.25rem; }
        .brand .mark { width: 34px; height: 34px; border-radius: 9px; background: linear-gradient(135deg, var(--accent), #1717ea); display: flex; align-items: center; justify-content: center; font-size: .95rem; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 1.75rem; }
        h1 { font-size: 1.35rem; margin-bottom: .35rem; }
        p.lead { color: var(--muted); margin-bottom: 1.5rem; font-size: .95rem; }
        .steps { display: flex; gap: .4rem; margin-bottom: 1.5rem; }
        .steps span { flex: 1; height: 4px; border-radius: 2px; background: var(--line); }
        .steps span.done { background: var(--accent); }
        .steps span.active { background: linear-gradient(90deg, var(--accent), #6366f1); }
        ul.reqs { list-style: none; display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1.5rem; }
        ul.reqs li { display: flex; align-items: center; gap: .65rem; padding: .55rem .8rem; border: 1px solid var(--line); border-radius: 9px; font-size: .9rem; }
        .dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
        .dot.ok { background: var(--ok); } .dot.bad { background: var(--bad); } .dot.warn { background: var(--warn); }
        .note { margin-left: auto; color: var(--muted); font-size: .8rem; font-family: ui-monospace, monospace; }
        label { display: block; font-size: .82rem; color: var(--muted); margin: .9rem 0 .3rem; }
        input, select { width: 100%; background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 9px; padding: .6rem .75rem; font-size: .95rem; }
        input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: .9rem; }
        button { margin-top: 1.5rem; width: 100%; background: var(--accent); color: #fff; border: 0; border-radius: 9px; padding: .7rem; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { filter: brightness(1.1); }
        button[disabled] { opacity: .45; cursor: not-allowed; }
        button.ghost { background: transparent; border: 1px solid var(--line); }
        .flash { border-radius: 9px; padding: .7rem .9rem; margin-bottom: 1.25rem; font-size: .9rem; }
        .flash.error { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.4); color: #fca5a5; white-space: pre-wrap; }
        .flash.status { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); color: #86efac; white-space: pre-wrap; }
        .field-error { color: #fca5a5; font-size: .8rem; margin-top: .25rem; }
        footer { text-align: center; color: var(--muted); font-size: .78rem; margin-top: 1.25rem; }
        .success-icon { width: 56px; height: 56px; border-radius: 50%; background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.4); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto 1rem; }
    </style>
    @if (! empty($customCss))<style>{{ $customCss }}</style>@endif
</head>
<body>
<div class="shell">
    <div class="brand">
        <span class="mark">AD</span> ApproveDesk <span style="color:var(--muted);font-weight:400;font-size:.8rem;margin-left:auto">Installer</span>
    </div>
    <div class="card">
        @php($current = (int) trim($__env->yieldContent('step') ?: '0'))
        <div class="steps">
            @foreach(range(1, 5) as $n)
                <span class="{{ $n < $current ? 'done' : ($n === $current ? 'active' : '') }}"></span>
            @endforeach
        </div>

        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif
        @if (session('status'))
            <div class="flash status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </div>
    <footer>ApproveDesk — Approval Workflow Application</footer>
</div>
</body>
</html>
