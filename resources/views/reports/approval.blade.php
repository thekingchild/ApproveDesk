<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['pdf' => $pdf ?? false])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Approval report') }} · {{ $submission->reference_number }}</title>
    @if ($icon = ($favicon_data_uri ?? $logo_data_uri))
        <link rel="icon" href="{{ $icon }}">
    @endif
    <style>
        @page { size: A4 portrait; margin: 16mm 14mm 20mm; }
        :root { --accent: {{ \App\Support\Branding::accentColor() }}; --ink: #18181b; --muted: #52525b; --line: #d4d4d8; --soft: #f4f4f5; }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: #ececf0; font: 14px/1.45 "DejaVu Sans", Arial, sans-serif; }
        .toolbar { align-items: center; display: flex; gap: 10px; justify-content: flex-end; margin: 18px auto 0; max-width: 210mm; padding: 0 12px; }
        .toolbar a, .toolbar button { background: white; border: 1px solid #a1a1aa; border-radius: 6px; color: #27272a; cursor: pointer; font: inherit; padding: 8px 13px; text-decoration: none; }
        .toolbar .primary { background: var(--accent); border-color: var(--accent); color: white; }
        .document { background: white; margin: 18px auto 36px; max-width: 210mm; min-height: 297mm; padding: 16mm 14mm 20mm; }
        .brand { border-bottom: 3px solid var(--accent); padding-bottom: 14px; }
        .brand-identity { align-items: center; display: flex; gap: 12px; max-width: 100%; min-width: 0; }
        .brand-name { font-size: 20px; font-weight: 700; line-height: 1.2; max-width: 520px; overflow-wrap: anywhere; word-break: break-word; }
        .brand-logo { display: block; flex: 0 0 auto; max-height: 52px; max-width: 140px; object-fit: contain; }
        .eyebrow { color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { font-size: 28px; line-height: 1.15; margin: 18px 0 6px; }
        h2 { border-bottom: 1px solid var(--line); font-size: 16px; margin: 20px 0 10px; padding-bottom: 6px; break-after: avoid; page-break-after: avoid; }
        h3 { font-size: 15px; margin: 0; }
        p { margin: 5px 0; }
        .heading-row { min-height: 120px; position: relative; }
        .heading-row > div { padding-right: 130px; }
        .approval-status-stamp { display: block; height: 112px; margin-top: 0; max-height: 112px; max-width: 112px; object-fit: contain; position: absolute; right: 0; top: 8px; width: 112px; }
        .reference { color: var(--muted); font-size: 12px; }
        .summary, .fields { display: grid; gap: 0 20px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .item { border-bottom: 1px solid #e4e4e7; padding: 8px 0; page-break-inside: avoid; overflow-wrap: anywhere; }
        dt { color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; }
        dd { margin: 2px 0 0; }
        .body { overflow-wrap: anywhere; }
        .body img { height: auto; max-width: 100%; }
        section { break-inside: avoid-page; page-break-inside: avoid; }
        section.allow-break { break-inside: auto; page-break-inside: auto; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th, td { border-bottom: 1px solid var(--line); padding: 7px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: var(--soft); color: #3f3f46; font-size: 10px; text-transform: uppercase; }
        .summary-table th { background: transparent; width: 18%; }
        .summary-table td { width: 32%; }
        .empty { color: var(--muted); font-style: italic; }
        .footer { border-top: 1px solid var(--line); color: var(--muted); font-size: 9px; margin-top: 28px; padding-top: 8px; }
        .running-footer { display: none; }
        .page-number::after { content: counter(page); }
        .pdf body { background: white; }
        .pdf .document { margin: 0; max-width: none; min-height: 0; padding: 0; }
        .pdf .running-footer { display: block; position: fixed; right: 0; bottom: -13mm; left: 0; margin-top: 0; }
        .pdf .screen-footer { display: none; }
        @media print {
            body { background: white; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .toolbar { display: none !important; }
            .document { margin: 0; max-width: none; min-height: 0; padding: 0; }
            .running-footer { display: block; position: fixed; right: 0; bottom: -13mm; left: 0; margin-top: 0; }
            .screen-footer { display: none; }
        }
    </style>
</head>
<body>
    <footer class="footer running-footer">
        {{ $submission->reference_number }} · {{ __('Generated') }} {{ $generated_at->format('d M Y H:i:s') }} · {{ $timezone }} · {{ __('Page') }}&nbsp;<span class="page-number"></span>
    </footer>

    @unless ($pdf ?? false)
        <nav class="toolbar" aria-label="{{ __('Report actions') }}">
            <a href="{{ route('submissions.show', $submission) }}">{{ __('Back to request') }}</a>
            <a href="{{ route('submissions.approval-report.download', $submission) }}">{{ __('Download PDF') }}</a>
            <button type="button" class="primary" onclick="window.print()">{{ __('Print') }}</button>
        </nav>
    @endunless

    <main class="document">
        <header class="brand">
            <div class="brand-identity" data-test="report-company-identity">
                @if ($logo_data_uri)
                    <img class="brand-logo" src="{{ $logo_data_uri }}" alt="">
                @elseif ($favicon_data_uri)
                    <img class="brand-logo" src="{{ $favicon_data_uri }}" alt="">
                @endif
                <div>
                    <div class="brand-name">{{ \App\Support\Branding::name() }}</div>
                    <div class="eyebrow">{{ __('Approval record') }}</div>
                </div>
            </div>
        </header>

        @php
            $statusLabel = str($submission->status->value)->replace('_', ' ')->title()->toString();
        @endphp
        <div class="heading-row">
            <div>
                <h1>{{ __('Approval report') }}</h1>
                <h3>{{ $submission->title }}</h3>
                <p class="reference">{{ __('Reference') }}: {{ $submission->reference_number }}</p>
            </div>
            <img
                class="approval-status-stamp"
                src="{{ $status_stamp_data_uri }}"
                alt="{{ $statusLabel }}"
                data-test="approval-status-stamp"
                data-status-stamp="{{ $status_stamp_key }}"
                aria-label="{{ __('Approval status: :status', ['status' => $statusLabel]) }}"
            >
        </div>

        <section>
            <h2>{{ __('Request summary') }}</h2>
            <table class="summary-table">
                <tbody>
                    <tr><th>{{ __('Request type') }}</th><td>{{ $request_type }}</td><th>{{ __('Requested by') }}</th><td>{{ $submission->submitter->name }}</td></tr>
                    <tr><th>{{ __('Status') }}</th><td>{{ $statusLabel }}</td><th>{{ __('Department') }}</th><td>{{ $submission->department?->name ?? '—' }}</td></tr>
                    <tr><th>{{ __('Priority') }}</th><td>{{ str($submission->priority->value)->title() }}</td><th>{{ __('Created') }}</th><td>{{ $submission->created_at?->timezone($timezone)->format('d M Y H:i') ?? '—' }}</td></tr>
                    <tr><th>{{ __('Due date') }}</th><td>{{ $submission->due_date?->format('d M Y') ?? '—' }}</td><th></th><td></td></tr>
                </tbody>
            </table>
        </section>

        @if (filled($body))
            <section class="allow-break">
                <h2>{{ __('Request details') }}</h2>
                <div class="body">{!! $body !!}</div>
            </section>
        @endif

        @if ($fields !== [])
            <section class="allow-break">
                <h2>{{ __('Custom fields') }}</h2>
                <dl class="fields">
                    @foreach ($fields as $field)
                        <div class="item"><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif

        <section class="allow-break">
            <h2>{{ __('Workflow progress') }}</h2>
            @if ($workflow_steps === [])
                <p class="empty">{{ __('No approval workflow has started.') }}</p>
            @else
                <table>
                    <thead><tr><th>{{ __('Step') }}</th><th>{{ __('Decided by / assigned to') }}</th><th>{{ __('State') }}</th></tr></thead>
                    <tbody>
                        @foreach ($workflow_steps as $step)
                            <tr><td>{{ $step['name'] }}</td><td>{{ $step['actors'] ?: '—' }}</td><td>{{ $step['state'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="allow-break">
            <h2>{{ __('Recorded decisions and actions') }}</h2>
            @if ($workflow_actions === [])
                <p class="empty">{{ __('No workflow actions recorded yet.') }}</p>
            @else
                <table>
                    <thead><tr><th>{{ __('Step') }}</th><th>{{ __('Actor') }}</th><th>{{ __('Outcome') }}</th><th>{{ __('Date and time') }}</th><th>{{ __('Comment') }}</th></tr></thead>
                    <tbody>
                        @foreach ($workflow_actions as $action)
                            <tr>
                                <td>{{ $action['step'] }}</td>
                                <td>{{ $action['actor'] }}</td>
                                <td>{{ $action['outcome'] }}</td>
                                <td>{{ $action['acted_at'] }}</td>
                                <td>{{ $action['comment'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="allow-break">
            <h2>{{ __('Attachment manifest') }}</h2>
            @if ($attachments === [])
                <p class="empty">{{ __('No attachments recorded.') }}</p>
            @else
                <table>
                    <thead><tr><th>{{ __('File') }}</th><th>{{ __('Type') }}</th><th>{{ __('Size') }}</th><th>{{ __('Uploaded by') }}</th></tr></thead>
                    <tbody>
                        @foreach ($attachments as $attachment)
                            <tr><td>{{ $attachment['name'] }}</td><td>{{ $attachment['type'] }}</td><td>{{ $attachment['size'] }}</td><td>{{ $attachment['uploaded_by'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <footer class="footer screen-footer">
            {{ $submission->reference_number }} · {{ __('Generated') }} {{ $generated_at->format('d M Y H:i:s') }} · {{ $timezone }} · {{ __('Page') }}&nbsp;<span class="page-number"></span>
        </footer>
    </main>
</body>
</html>
