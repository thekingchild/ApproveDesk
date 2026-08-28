<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

final class ApprovalReportPdfService
{
    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $document = new Dompdf($options);
        $document->setPaper('a4', 'portrait');
        $document->loadHtml(View::make('reports.approval', [...$report, 'pdf' => true])->render());
        $document->render();

        return $document->output();
    }
}
