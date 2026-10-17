<?php

namespace Tests\Feature\Platform;

use App\Actions\Users\DecideDisplayNameChangeAction;
use App\Actions\Users\RequestDisplayNameChangeAction;
use App\Actions\Users\StoreProfileImageAction;
use App\Enums\DisplayNameChangeStatus;
use App\Models\DisplayNameChange;
use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Notifications\DisplayNameChangeDecidedNotification;
use App\Services\Users\SystemOwnerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class IdentityAndPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_change_stays_pending_until_another_authorized_user_approves_it(): void
    {
        $requester = User::factory()->create(['name' => 'Original Name']);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('users.update');

        $change = app(RequestDisplayNameChangeAction::class)->execute($requester, '  Safer Name  ');

        $this->assertSame('Original Name', $requester->fresh()->name);
        $this->assertSame('Safer Name', $change->proposed_name);
        $this->assertSame(DisplayNameChangeStatus::Pending, $change->status);

        app(DecideDisplayNameChangeAction::class)->approve($reviewer, $change);

        $this->assertSame('Safer Name', $requester->fresh()->name);
        $this->assertSame(DisplayNameChangeStatus::Approved, $change->fresh()->status);
        $this->assertSame($reviewer->id, $change->fresh()->decided_by);
    }

    public function test_new_name_request_replaces_pending_request_and_requester_can_cancel_it(): void
    {
        $user = User::factory()->create(['name' => 'Current']);
        $action = app(RequestDisplayNameChangeAction::class);

        $first = $action->execute($user, 'First');
        $second = $action->execute($user, 'Second');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Second', $second->fresh()->proposed_name);

        $action->cancel($user, $second);
        $this->assertSame(DisplayNameChangeStatus::Cancelled, $second->fresh()->status);
        $this->assertSame('Current', $user->fresh()->name);
    }

    public function test_database_enforces_one_pending_name_change_per_user(): void
    {
        $user = User::factory()->create();
        DisplayNameChange::query()->create([
            'user_id' => $user->id,
            'pending_user_id' => $user->id,
            'proposed_name' => 'First',
            'normalized_name' => 'first',
        ]);

        $this->expectException(QueryException::class);
        DisplayNameChange::query()->create([
            'user_id' => $user->id,
            'pending_user_id' => $user->id,
            'proposed_name' => 'Second',
            'normalized_name' => 'second',
        ]);
    }

    public function test_self_approval_and_non_owner_decision_for_owner_are_forbidden(): void
    {
        $owner = User::factory()->create();
        SystemOwner::query()->create(['id' => 1, 'user_id' => $owner->id]);
        app(SystemOwnerService::class)->forget();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('users.update');

        $ownChange = app(RequestDisplayNameChangeAction::class)->execute($reviewer, 'Own New Name');
        try {
            app(DecideDisplayNameChangeAction::class)->approve($reviewer, $ownChange);
            $this->fail('Self approval should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $ownerChange = app(RequestDisplayNameChangeAction::class)->execute($owner, 'Owner New Name');
        $this->expectException(AuthorizationException::class);
        app(DecideDisplayNameChangeAction::class)->approve($reviewer, $ownerChange);
    }

    public function test_profile_image_is_reencoded_privately_replaced_removed_and_requires_authorization(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $upload = UploadedFile::fake()->image('photo.jpg', 900, 700)->size(500);

        app(StoreProfileImageAction::class)->execute($user, $upload);
        $user->refresh();
        $this->assertSame('image/png', $user->profile_image_mime);
        $this->assertStringStartsWith('profile-images/', $user->profile_image_path);
        $this->assertNotSame('photo.jpg', basename($user->profile_image_path));
        Storage::disk('local')->assertExists($user->profile_image_path);

        $this->actingAs($other)->get(route('profile-image.show', $user))->assertForbidden();
        $this->actingAs($user)->get(route('profile-image.show', $user))->assertOk()->assertHeader('Content-Type', 'image/png');

        $old = $user->profile_image_path;
        app(StoreProfileImageAction::class)->remove($user);
        Storage::disk('local')->assertMissing($old);
        $this->assertNull($user->fresh()->profile_image_path);
    }

    public function test_name_decisions_are_audited_notified_and_available_in_admin_review_ui(): void
    {
        Notification::fake();
        $requester = User::factory()->create(['name' => 'Current Name']);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['users.view', 'users.update']);
        $change = app(RequestDisplayNameChangeAction::class)->execute($requester, 'Requested Name');

        Livewire::actingAs($reviewer)
            ->test('admin.users.name-changes')
            ->assertSee('Requested Name')
            ->call('startReject', $change->id)->set('rejectionReason', 'Identity could not be verified.')->call('reject')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('display_name_changes', [
            'id' => $change->id,
            'status' => 'rejected',
            'decided_by' => $reviewer->id,
            'reason' => 'Identity could not be verified.',
            'pending_user_id' => null,
        ]);
        $this->assertDatabaseHas('activity_log', ['event' => 'display-name-rejected', 'causer_id' => $reviewer->id]);
        Notification::assertSentTo($requester, DisplayNameChangeDecidedNotification::class, fn ($notification): bool => $notification->status === 'rejected');
    }

    public function test_profile_image_rejects_disallowed_and_polyglot_content_and_replacement_cleans_up(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $action = app(StoreProfileImageAction::class);

        foreach ([
            UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('avatar.png', $this->png().'<?php echo 1;'),
            UploadedFile::fake()->image('avatar.gif', 20, 20),
        ] as $unsafe) {
            try {
                $action->execute($user, $unsafe);
                $this->fail('Unsafe image was accepted: '.$unsafe->getClientOriginalName());
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('photo', $exception->errors());
            }
        }

        $action->execute($user, UploadedFile::fake()->image('first.jpg', 600, 400));
        $old = $user->fresh()->profile_image_path;
        $action->execute($user, UploadedFile::fake()->image('second.webp', 400, 600));
        Storage::disk('local')->assertMissing($old);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($user->fresh()->profile_image_path));
        $this->assertLessThanOrEqual(512, $width);
        $this->assertSame($width, $height);
    }

    public function test_profile_images_are_visible_to_directory_users_and_render_in_user_menus(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $directoryUser = User::factory()->create();
        $directoryUser->givePermissionTo('users.view');
        app(StoreProfileImageAction::class)->execute($user, UploadedFile::fake()->image('avatar.png'));

        $this->actingAs($directoryUser)->get(route('profile-image.show', $user))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('profile-image.show', $user), false);
    }

    public function test_manifest_uses_private_branded_pwa_icons_and_approvedesk_fallback(): void
    {
        $fallback = $this->get(route('manifest'))->assertOk()->json();
        $this->assertSame('ApproveDesk', config('app.name'));
        $this->assertStringNotContainsString('favicon.svg', json_encode($fallback));
        $this->assertSame('/pwa/icon-192.png', $fallback['icons'][0]['src']);

        Setting::put('brand.meta_title', 'Acme Approvals');
        Setting::put('brand.logo', 'branding/'.Str::uuid().'.png');
        Storage::disk('local')->put(Setting::get('brand.logo'), $this->png());

        $manifest = $this->get(route('manifest'))->assertOk()->json();
        $this->assertSame('Acme Approvals', $manifest['name']);
        $this->assertSame('/pwa/icon-192.png', $manifest['icons'][0]['src']);
        $this->get('/pwa/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        foreach ([192, 512] as $size) {
            $response = $this->get("/pwa/icon-{$size}.png")->assertOk();
            [$width, $height] = getimagesizefromstring($response->getContent());
            $this->assertSame([$size, $size], [$width, $height]);
        }

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('/pwa/icon-32.png', false)
            ->assertSee('/pwa/icon-180.png', false);
        $this->assertStringNotContainsString('/favicon.svg', file_get_contents(public_path('sw.js')));
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR42mP8z8Dwn4GBgYGJAQoAHgIHAcZg9WQAAAAASUVORK5CYII=', true);
    }
}
