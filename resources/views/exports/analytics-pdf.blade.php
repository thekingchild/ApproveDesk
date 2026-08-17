<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ApproveDesk approval analytics</title>
    <style>
        @page { margin: 42px 42px 52px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #18181b; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.4; }
        h1, h2 { margin: 0; }
        h1 { color: #111827; font-size: 22px; }
        h2 { margin-bottom: 8px; color: #1f2937; font-size: 13px; }
        .muted { color: #6b7280; }
        .header { margin-bottom: 22px; border-bottom: 2px solid #2563eb; padding-bottom: 12px; }
        .header p { margin: 4px 0 0; }
        .section { margin-top: 20px; }
        .cards { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 5px; margin: 0 -5px; }
        .card { width: 33.33%; border: 1px solid #d4d4d8; padding: 9px; vertical-align: top; }
        .card-empty { border: 0; }
        .card-label { color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: .4px; }
        .card-value { margin-top: 4px; color: #111827; font-size: 17px; font-weight: bold; }
        .card-detail { margin-top: 2px; color: #71717a; font-size: 7px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { display: table-header-group; }
        table.data th { background: #2563eb; color: white; font-size: 8px; font-weight: bold; padding: 6px; text-align: left; }
        table.data td { border-bottom: 1px solid #e4e4e7; padding: 5px 6px; vertical-align: top; }
        table.data tr { page-break-inside: avoid; }
        .number { text-align: right; }
        .status-overdue { color: #b91c1c; font-weight: bold; }
        .status-ok { color: #52525b; }
        .footer { position: fixed; right: 0; bottom: -30px; left: 0; color: #71717a; font-size: 8px; }
        .footer-brand { float: left; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
@php
    $duration = static function (?int $minutes): string {
        if ($minutes === null) return 'N/A';
        if ($minutes < 60) return $minutes.'m';
        $hours = intdiv($minutes, 60);
        if ($hours < 24) return $hours.'h '.($minutes % 60).'m';
        return intdiv($hours, 24).'d '.($hours % 24).'h';
    };
@endphp

<div class="footer"><span class="footer-brand">ApproveDesk - Approval analytics</span></div>

<div class="header">
    <h1>Approval analytics</h1>
    <p class="muted">Workflows started {{ $report['from']->format('M j, Y') }} - {{ $report['to']->format('M j, Y') }}</p>
    <p class="muted">Generated {{ now()->format('M j, Y g:i A') }}. Approval rate uses resolved workflows only.</p>
</div>

<table class="cards">
    <tr>
        <td class="card"><div class="card-label">Started</div><div class="card-value">{{ number_format($report['summary']['started']) }}</div><div class="card-detail">workflows</div></td>
        <td class="card"><div class="card-label">Active</div><div class="card-value">{{ number_format($report['summary']['active']) }}</div><div class="card-detail">including on hold</div></td>
        <td class="card"><div class="card-label">Approval rate</div><div class="card-value">{{ $report['summary']['approval_rate'] === null ? 'N/A' : number_format($report['summary']['approval_rate'], 1).'%' }}</div><div class="card-detail">{{ $report['summary']['approved'] }} approved / {{ $report['summary']['rejected'] }} rejected</div></td>
    </tr>
    <tr>
        <td class="card"><div class="card-label">Median turnaround</div><div class="card-value">{{ $duration($report['summary']['median_turnaround_minutes']) }}</div><div class="card-detail">Average {{ $duration($report['summary']['average_turnaround_minutes']) }}</div></td>
        <td class="card"><div class="card-label">Overdue now</div><div class="card-value">{{ number_format($report['summary']['overdue']) }}</div><div class="card-detail">active SLA breaches</div></td>
        <td class="card card-empty"></td>
    </tr>
</table>

<div class="section">
    <h2>Step bottlenecks</h2>
    <table class="data">
        <thead><tr><th>Workflow</th><th>Step</th><th class="number">Avg completed</th><th class="number">Samples</th><th class="number">Open</th><th class="number">Overdue</th><th class="number">Oldest open</th></tr></thead>
        <tbody>
        @forelse ($report['bottlenecks'] as $step)
            <tr>
                <td>{{ $step['workflow'] }}</td><td>{{ $step['step'] }}</td>
                <td class="number">{{ $duration($step['average_minutes']) }}</td><td class="number">{{ $step['completed_samples'] }}</td>
                <td class="number">{{ $step['open'] }}</td><td class="number">{{ $step['overdue'] }}</td>
                <td class="number">{{ $duration($step['oldest_open_minutes']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No workflow step activity is available for this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Longest-waiting active workflows</h2>
    <table class="data">
        <thead><tr><th>Reference</th><th>Submission</th><th>Current step</th><th class="number">Waiting</th><th>SLA status</th></tr></thead>
        <tbody>
        @forelse ($report['slowest_active'] as $workflow)
            <tr>
                <td>{{ $workflow['reference'] }}</td><td>{{ $workflow['title'] }}</td><td>{{ $workflow['step'] }}</td>
                <td class="number">{{ $duration($workflow['elapsed_minutes']) }}</td>
                <td class="{{ $workflow['overdue'] ? 'status-overdue' : 'status-ok' }}">{{ $workflow['overdue'] ? 'Overdue' : 'Within SLA' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No active workflows in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="section page-break">
    <h2>Daily workflow activity</h2>
    <p class="muted">Workflow starts and resolutions by calendar day for the selected cohort.</p>
    <table class="data">
        <thead><tr><th>Date</th><th class="number">Started</th><th class="number">Resolved</th></tr></thead>
        <tbody>
        @foreach ($report['daily'] as $day)
            <tr><td>{{ $day['date'] }}</td><td class="number">{{ $day['started'] }}</td><td class="number">{{ $day['resolved'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
</body>
</html>
