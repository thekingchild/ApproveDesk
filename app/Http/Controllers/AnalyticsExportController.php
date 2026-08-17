<?php

namespace App\Http\Controllers;

use App\Enums\AnalyticsExportFormat;
use App\Http\Requests\AnalyticsExportRequest;
use App\Services\AnalyticsExportService;
use App\Services\AnalyticsService;
use Illuminate\Http\Response;

class AnalyticsExportController extends Controller
{
    public function __invoke(
        AnalyticsExportRequest $request,
        AnalyticsService $analytics,
        AnalyticsExportService $exporter,
    ): Response {
        $format = AnalyticsExportFormat::from($request->string('format')->toString());
        $report = $analytics->dashboard($request->integer('days'));
        $filename = 'approval-analytics-'.$report['from']->toDateString().'-to-'.$report['to']->toDateString().'.'.$format->value;

        return response($exporter->export($report, $format), 200, [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
