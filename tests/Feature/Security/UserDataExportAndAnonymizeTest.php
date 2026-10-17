<?php

namespace Tests\Feature\Security;

use App\Actions\Privacy\AnonymizeUserAction;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class UserDataExportAndAnonymizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_can_download_a_personal_data_export_without_secrets(): void
    {
        $user = User::factory()->create([
            'name' => 'Amina Bello',
            'email' => 'amina@example.com',
        ])->assignRole('submitter');
        Submission::factory()->for($user, 'submitter')->create(['title' => 'Leave in June']);

        $response = $this->actingAs($user)->get(route('privacy.export'));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $profile = (string) $zip->getFromName('profile.json');
        $submissions = (string) $zip->getFromName('submissions.json');
        $readme = (string) $zip->getFromName('README.txt');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('amina@example.com', $profile);
        $this->assertStringNotContainsString('password', $profile);
        $this->assertStringNotContainsString('two_factor_secret', $profile);
        $this->assertStringContainsString('Leave in June', $submissions);
        $this->assertStringContainsString('not included', $readme);
    }

    public function test_administrator_can_anonymize_a_user_and_keep_workflow_actions(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $target = User::factory()->create([
            'name' => 'Chioma Okeke',
            'email' => 'chioma@example.com',
            'phone' => '+2348012345678',
        ])->assignRole('approver');
        $action = WorkflowAction::factory()->create(['actor_id' => $target->id]);

        Livewire::actingAs($admin)
            ->test('admin.users.user-form')
            ->call('edit', $target->id)->call('anonymize')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->isAnonymized());
        $this->assertSame('Former user', $target->name);
        $this->assertSame('anonymized-'.$target->id.'@invalid.local', $target->email);
        $this->assertNull($target->phone);
        $this->assertFalse($target->is_active);
        $this->assertNotNull($action->fresh());
        $this->assertSame($target->id, $action->fresh()->actor_id);
    }

    public function test_user_with_history_cannot_hard_delete_their_account(): void
    {
        $user = User::factory()->create();
        Submission::factory()->for($user, 'submitter')->create();

        Livewire::actingAs($user)
            ->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_cannot_anonymize_self_or_the_last_super_admin(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        try {
            app(AnonymizeUserAction::class)->execute($admin, $admin);
            $this->fail('Self anonymize should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $super = User::factory()->create()->assignRole('super-admin');

        try {
            app(AnonymizeUserAction::class)->execute($super, $admin);
            $this->fail('Last super admin should not be anonymized.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $this->assertFalse($super->fresh()->isAnonymized());
    }
}
