<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\StoreBackupDestinationAction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BackupDestinationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_private_s3_endpoint_is_rejected_unless_opted_in(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(StoreBackupDestinationAction::class)->execute([
            'name' => 'MinIO',
            'kind' => 's3',
            'enabled' => true,
            'key' => 'id',
            'secret' => 'secret',
            'bucket' => 'bucket',
            'endpoint' => 'https://10.0.0.5:9000',
        ]);
    }

    public function test_private_s3_endpoint_is_accepted_when_opted_in(): void
    {
        config(['backups.allow_private_s3_endpoint' => true]);

        $destination = app(StoreBackupDestinationAction::class)->execute([
            'name' => 'MinIO',
            'kind' => 's3',
            'enabled' => true,
            'key' => 'id',
            'secret' => 'secret',
            'bucket' => 'bucket',
            'endpoint' => 'https://10.0.0.5:9000',
        ]);

        $this->assertSame('https://10.0.0.5:9000', $destination->credentials()['endpoint']);
    }

    public function test_administrator_cannot_save_a_private_s3_endpoint(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.backups.destinations.store'), [
            'name' => 'MinIO',
            'kind' => 's3',
            'key' => 'id',
            'secret' => 'secret',
            'bucket' => 'bucket',
            'endpoint' => 'https://10.0.0.5:9000',
        ])->assertSessionHasErrors('endpoint');
    }
}
