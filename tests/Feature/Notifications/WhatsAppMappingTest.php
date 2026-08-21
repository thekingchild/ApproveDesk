<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Enums\NotificationKind;
use App\Models\Submission;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Services\Notifications\ChannelSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_fetch_stores_catalog_and_unapproved_mapping_is_rejected(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [[
                    'name' => 'action_notice',
                    'language' => 'en',
                    'status' => 'PENDING',
                    'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
                ]],
            ]),
        ]);

        $settings = app(ChannelSettings::class);
        $settings->putCredentials(NotificationChannelKey::WhatsApp, [
            'waba_id' => 'waba',
            'phone_number_id' => 'pn',
            'access_token' => 'token',
        ]);

        $admin = User::factory()->create()->assignRole('admin');
        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->call('fetchWhatsApp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_message_templates', ['name' => 'action_notice', 'status' => 'PENDING']);

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('whatsappKind', NotificationKind::ActionRequired->value)
            ->set('whatsappTemplate', 'action_notice')
            ->set('whatsappLanguage', 'en')
            ->set('whatsappBindings', "recipient_name={{1}}\nreference_number={{2}}\ntitle={{3}}\nsubmitter_name={{4}}\napproval_url={{5}}")
            ->call('saveWhatsAppMap')
            ->assertHasErrors('whatsappBindings');
    }

    public function test_mapped_approved_template_sends_type_template_and_unmapped_kind_does_not_post(): void
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);

        WhatsappMessageTemplate::query()->create([
            'name' => 'action_notice',
            'language' => 'en',
            'status' => 'APPROVED',
            'placeholder_count' => 5,
            'components' => [],
        ]);

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::WhatsApp, true);
        $settings->putCredentials(NotificationChannelKey::WhatsApp, [
            'waba_id' => 'waba',
            'phone_number_id' => 'pn',
            'access_token' => 'token',
        ]);

        $admin = User::factory()->create()->assignRole('admin');
        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('whatsappKind', 'action_required')
            ->set('whatsappTemplate', 'action_notice')
            ->set('whatsappLanguage', 'en')
            ->set('whatsappBindings', "recipient_name={{1}}\nreference_number={{2}}\ntitle={{3}}\nsubmitter_name={{4}}\napproval_url={{5}}")
            ->call('saveWhatsAppMap')
            ->assertHasNoErrors();

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'phone' => '+15551234567',
        ]);
        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && $request['type'] === 'template');
    }
}
