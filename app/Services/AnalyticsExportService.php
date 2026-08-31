<?php

namespace App\Services;

use App\Enums\AnalyticsExportFormat;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class AnalyticsExportService
{
    /** @param array<string, mixed> $report */
    public function export(array $report, AnalyticsExportFormat $format): string
    {
        return match ($format) {
            AnalyticsExportFormat::Csv => $this->csv($report),
            AnalyticsExportFormat::Xlsx => $this->xlsx($report),
            AnalyticsExportFormat::Pdf => $this->pdf($report),
        };
    }

    /** @param array<string, mixed> $report */
    private function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the CSV export stream.');
        }

        $this->writeCsvRow($stream, ['ApproveDesk approval analytics']);
        $this->writeCsvRow($stream, ['Cohort', 'Workflows started in selected period']);
        $this->writeCsvRow($stream, ['From', $report['from']->toDateString(), 'To', $report['to']->toDateString()]);
        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Metric', 'Value']);

        foreach ($report['summary'] as $metric => $value) {
            $this->writeCsvRow($stream, [str_replace('_', ' ', ucfirst((string) $metric)), $value ?? 'N/A']);
        }

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Daily activity']);
        $this->writeCsvRow($stream, ['Date', 'Started', 'Resolved']);

        foreach ($report['daily'] as $day) {
            $this->writeCsvRow($stream, [$day['date'], $day['started'], $day['resolved']]);
        }

        $this->writeCsvRow($stream, []);
        $this->writeCsvRow($stream, ['Step bottlenecks']);
        $this->writeCsvRow($stream, ['Workflow', 'Step', 'Average completed minutes', 'Completed samples', 'Open', 'Overdue', 'Oldest open minutes']);

        foreach ($report['bottlenecks'] as $step) {
            $this->writeCsvRow($stream, [
                $step['workflow'],
                $step['step'],
                $step['average_minutes'] ?? 'N/A',
                $step['completed_samples'],
                $step['open'],
                $step['overdue'],
                $step['oldest_open_minutes'] ?? 'N/A',
            ]);
        }

        if (($report['custom_fields'] ?? []) !== []) {
            $this->writeCsvRow($stream, []);
            $this->writeCsvRow($stream, ['Request field breakdown']);
            $this->writeCsvRow($stream, ['Field', 'Value', 'Count']);

            foreach ($report['custom_fields'] as $field) {
                foreach ($field['values'] as $value) {
                    $this->writeCsvRow($stream, [$field['label'], $value['value'], $value['count']]);
                }
            }
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the CSV export stream.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $report */
    private function xlsx(array $report): string
    {
        $workbook = new Spreadsheet;
        $workbook->getProperties()
            ->setCreator('ApproveDesk')
            ->setTitle('Approval analytics')
            ->setSubject('Workflow performance and bottlenecks');

        $summary = $workbook->getActiveSheet();
        $summary->setTitle('Summary');
        $summary->mergeCells('A1:D1')->setCellValue('A1', 'ApproveDesk approval analytics');
        $summary->mergeCells('A2:D2')->setCellValue('A2', 'Workflows started '.$report['from']->format('M j, Y').' - '.$report['to']->format('M j, Y'));
        $summary->fromArray([
            ['Metric', 'Value'],
            ['Started workflows', $report['summary']['started']],
            ['Active workflows', $report['summary']['active']],
            ['Resolved workflows', $report['summary']['resolved']],
            ['Approved workflows', $report['summary']['approved']],
            ['Rejected workflows', $report['summary']['rejected']],
            ['Approval rate', $report['summary']['approval_rate'] === null ? null : $report['summary']['approval_rate'] / 100],
            ['Average turnaround (hours)', $report['summary']['average_turnaround_minutes'] === null ? null : $report['summary']['average_turnaround_minutes'] / 60],
            ['Median turnaround (hours)', $report['summary']['median_turnaround_minutes'] === null ? null : $report['summary']['median_turnaround_minutes'] / 60],
            ['Overdue active workflows', $report['summary']['overdue']],
        ], null, 'A4', true);
        $summary->getStyle('B10')->getNumberFormat()->setFormatCode('0.0%');
        $summary->getStyle('B11:B12')->getNumberFormat()->setFormatCode('0.0');
        $this->styleSheet($summary, 4, 13, 2);
        $summary->getColumnDimension('A')->setWidth(34);
        $summary->getColumnDimension('B')->setWidth(20);

        $daily = $workbook->createSheet();
        $daily->setTitle('Daily Activity');
        $daily->fromArray(['Date', 'Started', 'Resolved'], null, 'A1', true);
        $row = 2;

        foreach ($report['daily'] as $day) {
            $daily->setCellValue('A'.$row, Date::PHPToExcel(new DateTimeImmutable($day['date'])));
            $daily->setCellValue('B'.$row, $day['started']);
            $daily->setCellValue('C'.$row, $day['resolved']);
            $row++;
        }

        $daily->getStyle('A2:A'.($row - 1))->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $this->styleSheet($daily, 1, $row - 1, 3);
        $daily->getColumnDimension('A')->setWidth(16);
        $daily->getColumnDimension('B')->setWidth(14);
        $daily->getColumnDimension('C')->setWidth(14);

        $bottlenecks = $workbook->createSheet();
        $bottlenecks->setTitle('Bottlenecks');
        $bottlenecks->fromArray(['Workflow', 'Step', 'Avg completed (hours)', 'Completed samples', 'Open', 'Overdue', 'Oldest open (hours)'], null, 'A1', true);
        $row = 2;

        foreach ($report['bottlenecks'] as $step) {
            $this->setStringCell($bottlenecks, 'A'.$row, $step['workflow']);
            $this->setStringCell($bottlenecks, 'B'.$row, $step['step']);
            $bottlenecks->setCellValue('C'.$row, $step['average_minutes'] === null ? null : $step['average_minutes'] / 60);
            $bottlenecks->setCellValue('D'.$row, $step['completed_samples']);
            $bottlenecks->setCellValue('E'.$row, $step['open']);
            $bottlenecks->setCellValue('F'.$row, $step['overdue']);
            $bottlenecks->setCellValue('G'.$row, $step['oldest_open_minutes'] === null ? null : $step['oldest_open_minutes'] / 60);
            $row++;
        }

        if ($row > 2) {
            $bottlenecks->getStyle('C2:C'.($row - 1))->getNumberFormat()->setFormatCode('0.0');
            $bottlenecks->getStyle('G2:G'.($row - 1))->getNumberFormat()->setFormatCode('0.0');
        }

        $this->styleSheet($bottlenecks, 1, max(1, $row - 1), 7);
        foreach ([24, 24, 24, 20, 12, 12, 22] as $index => $width) {
            $bottlenecks->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $active = $workbook->createSheet();
        $active->setTitle('Active Workflows');
        $active->fromArray(['Reference', 'Title', 'Current step', 'Step started', 'Waiting (hours)', 'SLA status'], null, 'A1', true);
        $row = 2;

        foreach ($report['slowest_active'] as $workflow) {
            $this->setStringCell($active, 'A'.$row, $workflow['reference']);
            $this->setStringCell($active, 'B'.$row, $workflow['title']);
            $this->setStringCell($active, 'C'.$row, $workflow['step']);
            $active->setCellValue('D'.$row, Date::PHPToExcel($workflow['started_at']));
            $active->setCellValue('E'.$row, $workflow['elapsed_minutes'] / 60);
            $active->setCellValue('F'.$row, $workflow['overdue'] ? 'Overdue' : 'Within SLA');
            $row++;
        }

        if ($row > 2) {
            $active->getStyle('D2:D'.($row - 1))->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
            $active->getStyle('E2:E'.($row - 1))->getNumberFormat()->setFormatCode('0.0');
        }

        $this->styleSheet($active, 1, max(1, $row - 1), 6);
        foreach ([20, 42, 24, 20, 18, 16] as $index => $width) {
            $active->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        if (($report['custom_fields'] ?? []) !== []) {
            $fields = $workbook->createSheet();
            $fields->setTitle('Request Fields');
            $fields->fromArray(['Field', 'Value', 'Count'], null, 'A1', true);
            $row = 2;

            foreach ($report['custom_fields'] as $field) {
                foreach ($field['values'] as $value) {
                    $this->setStringCell($fields, 'A'.$row, $field['label']);
                    $this->setStringCell($fields, 'B'.$row, $value['value']);
                    $fields->setCellValue('C'.$row, $value['count']);
                    $row++;
                }
            }

            $this->styleSheet($fields, 1, max(1, $row - 1), 3);
            foreach ([34, 42, 12] as $index => $width) {
                $fields->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
            }
        }

        $temporary = tempnam(sys_get_temp_dir(), 'approvedesk-xlsx-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create the XLSX export file.');
        }

        try {
            (new Xlsx($workbook))->save($temporary);
            $contents = file_get_contents($temporary);
        } finally {
            $workbook->disconnectWorksheets();
            @unlink($temporary);
        }

        if ($contents === false) {
            throw new RuntimeException('Unable to read the XLSX export file.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $report */
    private function pdf(array $report): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $document = new Dompdf($options);
        $document->setPaper('A4', 'portrait');
        $document->loadHtml(View::make('exports.analytics-pdf', ['report' => $report])->render());
        $document->render();

        $font = $document->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $document->getCanvas()->page_text(500, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.4]);

        return $document->output();
    }

    /**
     * @param  resource  $stream
     * @param  list<mixed>  $row
     */
    private function writeCsvRow($stream, array $row): void
    {
        fputcsv($stream, array_map($this->csvCell(...), $row));
    }

    private function csvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($this->isCsvFormula($value)) {
            return "'".$value;
        }

        return $value;
    }

    private function isCsvFormula(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $first = $value[0];

        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return true;
        }

        return preg_match('/\A[\s]+[=+\-@]/', $value) === 1;
    }

    private function setStringCell(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    private function styleSheet(Worksheet $sheet, int $headerRow, int $lastRow, int $lastColumn): void
    {
        $lastColumnName = Coordinate::stringFromColumnIndex($lastColumn);
        $range = 'A'.$headerRow.':'.$lastColumnName.max($headerRow, $lastRow);
        $printRange = 'A'.($headerRow > 1 ? 1 : $headerRow).':'.$lastColumnName.max($headerRow, $lastRow);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter($range);
        $sheet->getStyle('A'.$headerRow.':'.$lastColumnName.$headerRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);
        $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getSheetView()->setShowZeros(true);
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($lastColumn > 3 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setPrintArea($printRange);
        $sheet->getPageMargins()->setTop(0.5)->setRight(0.4)->setBottom(0.5)->setLeft(0.4);

        if ($headerRow > 1) {
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new Color('1F2937'));
            $sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new Color('6B7280'));
        }
    }
}
