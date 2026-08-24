<?php

namespace App\Actions\Privacy;

use App\Models\User;
use App\Services\Privacy\UserDataExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class ExportUserDataAction
{
    public function __construct(private readonly UserDataExporter $exporter) {}

    public function execute(User $user): StreamedResponse
    {
        $files = $this->exporter->files($user);
        $tmp = tempnam(sys_get_temp_dir(), 'adu');

        if ($tmp === false) {
            throw new \RuntimeException('Unable to create the data export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to create the data export.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return response()->streamDownload(function () use ($tmp): void {
            readfile($tmp);
            @unlink($tmp);
        }, 'approvedesk-my-data-'.$user->id.'.zip', [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
