<?php

namespace Tests\Feature\Security;

use App\Models\BackupDestination;
use App\Models\PushSubscription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RV-LAR-05: stored secrets are encrypted, and an unreadable one is reported rather than hidden. */
class StoredSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_push_auth_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('settings.push-subscriptions.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'plain-auth-secret'],
        ])->assertOk();

        $raw = DB::table('push_subscriptions')->value('auth_token');
        $this->assertNotSame('plain-auth-secret', $raw);
        $this->assertSame('plain-auth-secret', PushSubscription::query()->sole()->auth_token);
    }

    public function test_the_migration_encrypts_existing_plain_secrets(): void
    {
        $user = User::factory()->create();
        DB::table('push_subscriptions')->insert([
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/old',
            'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/fcm/send/old'),
            'public_key' => 'public-key',
            'auth_token' => 'legacy-plain-secret',
            'content_encoding' => 'aes128gcm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_10_18_000000_encrypt_push_subscription_auth_tokens.php'))->up();

        $this->assertNotSame('legacy-plain-secret', DB::table('push_subscriptions')->value('auth_token'));
        $this->assertSame('legacy-plain-secret', PushSubscription::query()->sole()->auth_token);
    }

    public function test_unreadable_backup_credentials_are_flagged_not_hidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $destination = BackupDestination::factory()->s3()->create();
        $destination->forceFill(['config' => 'not-something-this-app-key-can-read'])->save();

        $this->assertTrue($destination->fresh()->credentialsUnreadable());
        $this->assertFalse($destination->fresh()->isConfigured());

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('data-test="destination-credentials-unreadable"', false);
    }
}
