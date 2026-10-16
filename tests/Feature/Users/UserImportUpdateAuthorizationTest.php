<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-SEC-06: "update existing" imports follow the same rules as editing a
 * user by hand, and the analysed preview cannot be rewritten by the browser.
 */
class UserImportUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_importer_without_update_permission_cannot_rewrite_existing_users(): void
    {
        $importer = User::factory()->create(['email' => 'importer@example.com']);
        $importer->givePermissionTo(['users.view', 'users.create', 'users.import']);
        $victim = User::factory()->create(['email' => 'victim@example.com', 'name' => 'Original Name'])->assignRole('submitter');

        $this->importAs($importer, "Renamed,victim@example.com,,importer@example.com,,\n");

        $victim->refresh();
        $this->assertSame('Original Name', $victim->name);
        $this->assertNull($victim->manager_id);
    }

    public function test_non_owner_administrator_cannot_rewrite_another_administrator(): void
    {
        $admin = User::factory()->create(['email' => 'admin-one@example.com'])->assignRole('admin');
        $peer = User::factory()->create(['email' => 'admin-two@example.com', 'name' => 'Peer Admin'])->assignRole('admin');

        $this->importAs($admin, "Renamed Peer,admin-two@example.com,,admin-one@example.com,,\n");

        $this->assertSame('Peer Admin', $peer->fresh()->name);
        $this->assertNull($peer->fresh()->manager_id);
    }

    public function test_user_administrator_can_still_update_ordinary_users(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $user = User::factory()->create(['email' => 'person@example.com', 'name' => 'Old Name'])->assignRole('submitter');

        $this->importAs($admin, "New Name,person@example.com,,,,\n");

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_the_analysed_preview_cannot_be_rewritten_from_the_browser(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($admin)
            ->test('pages::admin.users-import')
            ->set('preview', [['name' => 'Forged', 'email' => 'forged@example.com', 'status' => 'ready']]);
    }

    private function importAs(User $actor, string $rows): void
    {
        $csv = "name,email,department,manager_email,phone,roles\n".$rows;

        Livewire::actingAs($actor)
            ->test('pages::admin.users-import')
            ->set('file', UploadedFile::fake()->createWithContent('users.csv', $csv))
            ->call('analyze')
            ->set('duplicateMode', 'update')
            ->call('commit');
    }
}
