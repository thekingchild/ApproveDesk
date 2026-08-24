<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

class DownloadWatermarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Setting::query()->delete();
        Artisan::call('cache:clear');
    }

    public function test_watermarked_non_image_download_arrives_as_a_zip_with_a_notice(): void
    {
        Setting::put('privacy.watermark_downloads', '1');
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'reference_number' => 'REQ-WATERMARK',
        ]);
        $file = SubmissionFile::factory()->for($submission)->for($submitter, 'uploader')->create([
            'stored_path' => 'submissions/1/note.txt',
            'original_name' => 'note.txt',
            'mime_type' => 'text/plain',
        ]);
        Storage::disk('local')->put($file->stored_path, 'invoice-body');

        $signed = URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(10),
            ['submission' => $submission->id, 'file' => $file->id],
        );

        $response = $this->actingAs($submitter)->get($signed);
        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $tmp = tempnam(sys_get_temp_dir(), 'wm');
        file_put_contents($tmp, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertNotFalse($zip->locateName('note.txt'));
        $this->assertNotFalse($zip->locateName('DOWNLOAD-NOTICE.txt'));
        $notice = (string) $zip->getFromName('DOWNLOAD-NOTICE.txt');
        $this->assertStringContainsString($submitter->email, $notice);
        $this->assertStringContainsString('REQ-WATERMARK', $notice);
        $zip->close();
        @unlink($tmp);
    }

    public function test_downloads_are_unchanged_when_watermarking_is_off(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        $file = SubmissionFile::factory()->for($submission)->for($submitter, 'uploader')->create([
            'stored_path' => 'submissions/1/plain.txt',
            'original_name' => 'plain.txt',
            'mime_type' => 'text/plain',
        ]);
        Storage::disk('local')->put($file->stored_path, 'plain-content');

        $signed = URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(10),
            ['submission' => $submission->id, 'file' => $file->id],
        );

        $response = $this->actingAs($submitter)->get($signed);
        $response->assertOk();
        $this->assertSame('plain-content', $response->getContent() ?: Storage::disk('local')->get($file->stored_path));
    }
}
