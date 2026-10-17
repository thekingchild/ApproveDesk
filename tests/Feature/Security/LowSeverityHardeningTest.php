<?php

namespace Tests\Feature\Security;

use App\Actions\Submissions\AttachSubmissionFilesAction;
use App\Models\BackupDestination;
use App\Models\IntegrationConnector;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Low-severity review findings fixed together: RV-SEC-24, RV-LAR-05, RV-LAR-09, RV-LAR-21. */
class LowSeverityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rv_sec_24_branding_route_never_serves_svg_or_gif(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        Storage::disk('public')->put('branding/logo.gif', 'GIF89a');
        Storage::disk('public')->put('branding/logo.png', 'png');

        $this->get('/branding/assets/logo.svg')->assertNotFound();
        $this->get('/branding/assets/logo.gif')->assertNotFound();
        $this->get('/branding/assets/logo.png')->assertOk();
    }

    public function test_rv_lar_05_secrets_never_appear_in_arrays(): void
    {
        $this->assertArrayNotHasKey('credentials', IntegrationConnector::factory()->create()->toArray());
        $this->assertArrayNotHasKey('secret', WebhookEndpoint::factory()->create()->toArray());
        $this->assertArrayNotHasKey('config', BackupDestination::factory()->create()->toArray());
    }

    public function test_rv_lar_21_a_request_holds_at_most_the_attachment_cap(): void
    {
        Storage::fake('local');
        $submission = Submission::factory()->create();
        SubmissionFile::factory()->count(AttachSubmissionFilesAction::MAX_FILES)->create(['submission_id' => $submission->id]);

        try {
            app(AttachSubmissionFilesAction::class)->execute($submission, $submission->submitter, [UploadedFile::fake()->create('one-more.pdf', 10, 'application/pdf')]);
            $this->fail('A file over the cap was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments', $exception->errors());
        }
    }

    public function test_rv_lar_21_svg_uploads_are_never_previewed(): void
    {
        $this->assertNotContains('svg', config('livewire.temporary_file_upload.preview_mimes'));
    }

    public function test_rv_lar_21_only_a_zip_can_be_verified_as_an_evidence_pack(): void
    {
        $owner = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($owner)->test('pages::admin.records')
            ->set('verifyFile', UploadedFile::fake()->create('pack.exe', 5))
            ->call('verifyPack')
            ->assertHasErrors(['verifyFile' => 'mimes']);
    }

    public function test_rv_lar_09_turnstile_settings_need_the_security_ability(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('submitter'))
            ->post(route('settings.turnstile.credentials'), ['site_key' => 'x'])
            ->assertForbidden();
    }
}
