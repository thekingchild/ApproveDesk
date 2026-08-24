<?php

namespace App\Actions\Audit;

use App\Models\AuditExportLog;
use App\Models\EvidencePack;
use App\Models\Submission;
use App\Models\User;
use App\Services\Audit\EvidencePackBuilder;
use App\Services\Audit\EvidencePackSigner;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

final class GenerateEvidencePackAction
{
    public function __construct(
        private readonly EvidencePackBuilder $builder,
        private readonly EvidencePackSigner $signer,
    ) {}

    public function execute(Submission $submission, User $actor): EvidencePack
    {
        $files = $this->builder->files($submission);
        $hashes = [];
        foreach ($files as $name => $contents) {
            $hashes[$name] = hash('sha256', $contents);
        }

        $signature = $this->signer->sign($hashes);
        $manifest = json_encode([
            'generated_at' => now()->toIso8601String(),
            'submission_id' => $submission->id,
            'files' => $hashes,
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $relative = 'evidence/'.$submission->id.'/'.now()->format('YmdHis').'-'.$submission->id.'.zip';
        $absolute = Storage::disk('local')->path($relative);
        Storage::disk('local')->makeDirectory(dirname($relative));

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the evidence pack.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->addFromString('manifest.json', $manifest);
        $zip->close();

        $pack = EvidencePack::query()->create([
            'submission_id' => $submission->id,
            'generated_by' => $actor->id,
            'stored_path' => $relative,
            'disk' => 'local',
            'manifest_hash' => hash('sha256', $manifest),
            'signature' => $signature,
        ]);

        AuditExportLog::query()->create([
            'user_id' => $actor->id,
            'submission_id' => $submission->id,
            'kind' => 'evidence_pack',
        ]);

        return $pack;
    }
}
