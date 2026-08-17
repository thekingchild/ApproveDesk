<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsExportRequest;
use App\Services\AnalyticsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsExportController extends Controller
{
    public function __invoke(AnalyticsExportRequest $request, AnalyticsService $analytics): StreamedResponse
    {
        $days = $request->integer('days');
        $report = $analytics->dashboard($days);
        $filename = 'approval-analytics-'.$report['from']->toDateString().'-to-'.$report['to']->toDateString().'.csv';

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, ['ApproveDesk approval analytics']);
            fputcsv($stream, ['Cohort', 'Workflows started in selected period']);
            fputcsv($stream, ['From', $report['from']->toDateString(), 'To', $report['to']->toDateString()]);
            fputcsv($stream, []);
            fputcsv($stream, ['Metric', 'Value']);

            foreach ($report['summary'] as $metric => $value) {
                fputcsv($stream, [str_replace('_', ' ', ucfirst($metric)), $value ?? 'N/A']);
            }

            fputcsv($stream, []);
            fputcsv($stream, ['Daily activity']);
            fputcsv($stream, ['Date', 'Started', 'Resolved']);

            foreach ($report['daily'] as $day) {
                fputcsv($stream, [$day['date'], $day['started'], $day['resolved']]);
            }

            fputcsv($stream, []);
            fputcsv($stream, ['Step bottlenecks']);
            fputcsv($stream, ['Workflow', 'Step', 'Average completed minutes', 'Completed samples', 'Open', 'Overdue', 'Oldest open minutes']);

            foreach ($report['bottlenecks'] as $step) {
                fputcsv($stream, [
                    $step['workflow'],
                    $step['step'],
                    $step['average_minutes'] ?? 'N/A',
                    $step['completed_samples'],
                    $step['open'],
                    $step['overdue'],
                    $step['oldest_open_minutes'] ?? 'N/A',
                ]);
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
