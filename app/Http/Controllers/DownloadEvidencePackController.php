<?php

namespace App\Http\Controllers;

use App\Actions\Audit\GenerateEvidencePackAction;
use App\Models\Submission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadEvidencePackController extends Controller
{
    public function __invoke(Submission $submission, GenerateEvidencePackAction $generate): StreamedResponse
    {
        Gate::authorize('downloadEvidence', $submission);

        $pack = $generate->execute($submission, request()->user());

        return Storage::disk($pack->disk)->download(
            $pack->stored_path,
            ($submission->reference_number ?? 'request').'-evidence.zip',
        );
    }
}
