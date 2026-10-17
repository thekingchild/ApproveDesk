<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\StoreBackupDestinationAction;
use App\Contracts\DnsResolver;
use App\Models\User;
use App\Services\Backups\BackupTransportFactory;
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

    public function test_rv_lar_08_destination_update_is_validated_like_create(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $destination = app(StoreBackupDestinationAction::class)->execute([
            'name' => 'Offsite', 'kind' => 's3', 'enabled' => true,
            'key' => 'id', 'secret' => 'secret', 'bucket' => 'bucket',
        ]);

        $this->actingAs($admin)->post(route('admin.backups.destinations.update', $destination), [
            'name' => str_repeat('x', 81),
            'endpoint' => 'https://10.0.0.5:9000',
            'bucket' => ['not', 'a', 'string'],
        ])->assertSessionHasErrors(['name', 'endpoint', 'bucket']);

        $this->assertSame('Offsite', $destination->fresh()->name);
        $this->assertArrayNotHasKey('endpoint', array_filter($destination->fresh()->credentials()));

        $this->actingAs($admin)->post(route('admin.backups.destinations.update', $destination), [
            'name' => 'Offsite copy',
            'enabled' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.backups.index'));

        $this->assertSame('Offsite copy', $destination->fresh()->name);
        $this->assertSame('bucket', $destination->fresh()->credentials()['bucket'], 'Fields not posted are kept.');
    }

    public function test_virtual_hosted_bucket_name_resolving_to_a_private_address_is_refused(): void
    {
        // RV-SEC-17: {bucket}.{endpoint} is what the SDK actually connects to.
        $destination = app(StoreBackupDestinationAction::class)->execute([
            'name' => 'Object storage',
            'kind' => 's3',
            'enabled' => true,
            'key' => 'id',
            'secret' => 'secret',
            'bucket' => 'internal',
            'endpoint' => 'https://storage.example.com',
        ]);
        app(DnsResolver::class)->set('internal.storage.example.com', ['10.0.0.8']);

        $this->expectException(InvalidArgumentException::class);
        app(BackupTransportFactory::class)->make($destination)->test();
    }
}
