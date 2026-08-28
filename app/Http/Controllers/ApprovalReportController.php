<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Services\ApprovalReportPdfService;
use App\Services\ApprovalReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ApprovalReportController extends Controller
{
    public function show(Submission $submission): View
    {
        Gate::authorize('view', $submission);

        return view('reports.approval', app(ApprovalReportService::class)->build($submission, Auth::user()));
    }

    public function download(Submission $submission): Response
    {
        Gate::authorize('view', $submission);

        $report = app(ApprovalReportService::class)->build($submission, Auth::user());
        $pdf = app(ApprovalReportPdfService::class)->render($report);
        $reference = Str::of((string) $submission->reference_number)
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-')
            ->limit(100, '')
            ->toString();
        $filename = ($reference !== '' ? $reference : 'request').'-approval-report.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
