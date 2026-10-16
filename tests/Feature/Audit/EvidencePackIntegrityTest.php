<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\GenerateEvidencePackAction;
use App\Actions\Collaboration\EditCommentAction;
use App\Actions\Collaboration\PostCommentAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionFileRevision;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Audit\EvidencePackSigner;
use App\Services\Audit\EvidencePackVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * RV-SEC-21: the verifier refuses files that are not in the manifest and a
 * changed manifest; older packs are reported as unsigned (legacy).
 * RV-DATA-07: packs carry the request, comment and file histories and the
 * in-app notifications about the request.
 */
class EvidencePackIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    public function test_a_fresh_pack_is_verified_and_signs_its_metadata(): void
    {
        $path = $this->packPath();

        $result = app(EvidencePackVerifier::class)->verifyPath($path);

        $this->assertTrue($result->ok, $result->error);
        $this->assertFalse($result->legacy);
        $this->assertTrue($result->verified());
        $this->assertStringStartsWith('Verified', $result->message());
        $this->artisan('evidence:verify', ['path' => $path])
            ->expectsOutputToContain('verified')
            ->assertSuccessful();
    }

    public function test_a_file_injected_into_the_zip_is_refused(): void
    {
        $path = $this->packPath();

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('decision-override.json', '{"status":"rejected"}');
        $zip->close();

        $result = app(EvidencePackVerifier::class)->verifyPath($path);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('decision-override.json', $result->error);
        $this->artisan('evidence:verify', ['path' => $path])->assertFailed();
    }

    public function test_changing_manifest_metadata_breaks_the_signature(): void
    {
        $path = $this->packPath();

        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['generated_at'] = '2020-01-01T00:00:00+00:00';
        $manifest['submission_id'] = 999;
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->close();

        $result = app(EvidencePackVerifier::class)->verifyPath($path);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('signature', $result->error);
    }

    public function test_dropping_the_format_to_pass_as_a_legacy_pack_is_refused(): void
    {
        $path = $this->packPath();

        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        unset($manifest['format']);
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->close();

        $this->assertFalse(app(EvidencePackVerifier::class)->verifyPath($path)->ok);
    }

    public function test_a_pack_signed_before_a_key_rotation_still_verifies(): void
    {
        $path = $this->packPath();
        $old = (string) config('app.key');

        config(['app.key' => 'base64:'.base64_encode(Str::random(32)), 'app.previous_keys' => [$old]]);

        $this->assertTrue(app(EvidencePackVerifier::class)->verifyPath($path)->verified());
    }

    public function test_a_legacy_pack_is_reported_as_unsigned_not_verified(): void
    {
        $path = $this->legacyPack(['request.json' => '{"id":1}']);

        $result = app(EvidencePackVerifier::class)->verifyPath($path);

        $this->assertTrue($result->ok, $result->error);
        $this->assertTrue($result->legacy);
        $this->assertFalse($result->verified());
        $this->assertStringStartsWith('Unsigned (legacy)', $result->message());
        $this->artisan('evidence:verify', ['path' => $path])
            ->expectsOutputToContain('Unsigned (legacy)')
            ->assertSuccessful();

        Livewire::actingAs($this->admin)
            ->test('pages::admin.records')
            ->set('verifyFile', UploadedFile::fake()->createWithContent('legacy.zip', (string) file_get_contents($path)))
            ->call('verifyPack')
            ->assertSet('verifyResult', $result->message());
    }

    public function test_a_legacy_pack_with_an_injected_file_is_refused(): void
    {
        $path = $this->legacyPack(['request.json' => '{"id":1}'], ['extra.json' => '{}']);

        $this->assertFalse(app(EvidencePackVerifier::class)->verifyPath($path)->ok);
    }

    public function test_pack_includes_request_comment_and_file_histories_and_in_app_notifications(): void
    {
        $submission = $this->submitted();
        $this->actingAs($this->submitter);
        $submission->update(['title' => 'Q3 invoice pack (revised)']);

        $comment = app(PostCommentAction::class)->execute($submission, $this->submitter, 'Original: amount is 5,000');
        app(EditCommentAction::class)->execute($comment, $this->submitter, 'Edited: amount is 50,000');

        SubmissionFileRevision::query()->create([
            'submission_id' => $submission->id,
            'actor_id' => $this->submitter->id,
            'action' => 'removed',
            'original_name' => 'quote.pdf',
            'sha256' => hash('sha256', 'quote'),
            'size_bytes' => 5,
        ]);

        $other = $this->submitted();
        foreach ([[$submission, 'action_required'], [$other, 'other_request']] as [$about, $kind]) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\ActionRequiredNotification',
                'notifiable_type' => $this->approver->getMorphClass(),
                'notifiable_id' => $this->approver->id,
                'data' => json_encode(['submission_id' => $about->id, 'kind' => $kind]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $files = $this->packFiles(app(GenerateEvidencePackAction::class)->execute($submission->fresh(), $this->admin)->stored_path);

        $requestRevisions = json_decode($files['request_revisions.json'], true);
        $this->assertContains('Q3 invoice pack (revised)', array_map(fn (array $row) => $row['after']['title'] ?? null, $requestRevisions));

        $commentRevisions = json_decode($files['comment_revisions.json'], true);
        $this->assertSame('Original: amount is 5,000', $commentRevisions[0]['previous_body']);
        $this->assertSame($comment->id, $commentRevisions[0]['comment_id']);
        $this->assertSame($this->submitter->name, $commentRevisions[0]['editor']);

        $fileHistory = json_decode($files['file_history.json'], true);
        $this->assertSame(['removed', 'quote.pdf', $this->submitter->name], [$fileHistory[0]['action'], $fileHistory[0]['name'], $fileHistory[0]['actor']]);

        $inApp = json_decode($files['in_app_notifications.json'], true);
        $this->assertCount(1, $inApp);
        $this->assertSame(['action_required', $this->approver->name], [$inApp[0]['kind'], $inApp[0]['user']]);

        $manifest = json_decode($files['manifest.json'], true);
        foreach (['request_revisions.json', 'comment_revisions.json', 'file_history.json', 'in_app_notifications.json'] as $name) {
            $this->assertArrayHasKey($name, $manifest['files']);
        }
    }

    private function submitted(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
            'title' => 'Q3 invoice pack',
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }

    private function packPath(): string
    {
        $pack = app(GenerateEvidencePackAction::class)->execute($this->submitted(), $this->admin);

        return Storage::disk('local')->path($pack->stored_path);
    }

    /** @return array<string, string> */
    private function packFiles(string $storedPath): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($storedPath));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    /**
     * A pack in the pre-RV-SEC-21 layout: only the file-hash map is signed, with the raw APP_KEY.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $unlisted
     */
    private function legacyPack(array $files, array $unlisted = []): string
    {
        $hashes = array_map(fn (string $contents): string => hash('sha256', $contents), $files);
        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'submission_id' => 1,
            'files' => $hashes,
            'signature' => hash_hmac('sha256', app(EvidencePackSigner::class)->canonical($hashes), (string) config('app.key')),
        ];

        $path = Storage::disk('local')->path('legacy-'.Str::random(6).'.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ([...$files, ...$unlisted] as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->close();

        return $path;
    }
}
