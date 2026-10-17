<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\LinkTelegramAccountAction;
use App\Enums\NotificationChannelKey;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Notifications\ChannelSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** RV-ARC-11: Telegram "/start {token}" linking, previously untested and outside the identity rules. */
class TelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, ['bot_token' => '123:abc', 'webhook_secret' => 'hook-secret']);
    }

    public function test_start_links_the_account_once_and_replies(): void
    {
        $user = User::factory()->create();
        $token = LinkTelegramAccountAction::issueToken($user);

        $this->start($token, 555001)->assertNoContent();

        $this->assertSame('555001', $user->fresh()->telegram_user_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '555001'
            && str_contains($request['text'], 'is linked'));
        $this->assertTrue(Activity::query()->where('event', 'channel-identity-changed')->where('subject_id', $user->id)->exists());

        // Single use: the same token cannot link a second Telegram account.
        $this->start($token, 555002)->assertNoContent();
        $this->assertSame('555001', $user->fresh()->telegram_user_id);
    }

    public function test_an_unknown_token_links_nothing_and_stays_silent(): void
    {
        $this->start('not-a-token', 555003)->assertNoContent();

        $this->assertSame(0, User::query()->whereNotNull('telegram_user_id')->count());
        Http::assertNothingSent();
    }

    public function test_a_telegram_account_cannot_be_linked_to_two_people(): void
    {
        User::factory()->create(['telegram_user_id' => '555004']);
        $second = User::factory()->create();

        $this->start(LinkTelegramAccountAction::issueToken($second), 555004)->assertNoContent();

        $this->assertNull($second->fresh()->telegram_user_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'already linked to another'));
    }

    public function test_a_deactivated_person_cannot_link(): void
    {
        $user = User::factory()->create();
        $token = LinkTelegramAccountAction::issueToken($user);
        $user->update(['is_active' => false]);

        $this->start($token, 555005)->assertNoContent();

        $this->assertNull($user->fresh()->telegram_user_id);
    }

    public function test_the_system_owner_can_link_their_own_account(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        $this->start(LinkTelegramAccountAction::issueToken($owner), 555006)->assertNoContent();

        $this->assertSame('555006', $owner->fresh()->telegram_user_id);
    }

    public function test_a_wrong_webhook_secret_is_refused(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('integrations.telegram.webhook'), $this->update(LinkTelegramAccountAction::issueToken($user), 555007), [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();

        $this->assertNull($user->fresh()->telegram_user_id);
    }

    private function start(string $token, int $fromId): TestResponse
    {
        return $this->postJson(route('integrations.telegram.webhook'), $this->update($token, $fromId), [
            'X-Telegram-Bot-Api-Secret-Token' => 'hook-secret',
        ]);
    }

    /** @return array<string, mixed> */
    private function update(string $token, int $fromId): array
    {
        return ['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => $fromId], 'chat' => ['id' => $fromId], 'text' => '/start '.$token]];
    }
}
