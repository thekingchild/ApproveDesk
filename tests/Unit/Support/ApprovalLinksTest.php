<?php

namespace Tests\Unit\Support;

use App\Enums\WorkflowActionType;
use App\Models\BackupSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use App\Support\BackupDownloadLinks;
use App\Support\ApprovalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApprovalLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_factor_requirement_is_enabled_only_by_exact_one(): void
    {
        $this->assertFalse(ApprovalLinks::requiresSecondFactor());

        Setting::put('approvals.email_require_second_factor', '1');
        $this->assertTrue(ApprovalLinks::requiresSecondFactor());

        foreach (['0', 'true', '01', ' 1'] as $value) {
            Setting::put('approvals.email_require_second_factor', $value);
            $this->assertFalse(ApprovalLinks::requiresSecondFactor());
        }
    }

    public function test_chat_token_keeps_the_format_already_sent_cards_carry(): void
    {
        $this->freezeTime();
        [$instance, $step, $approver] = $this->seat();

        // Built exactly as the former ChatActionToken did, so buttons sent before this change still work.
        $payload = implode('|', [$instance->id, $step->id, $approver->id, 'approve', now()->addHours(ApprovalLinks::expirationHours())->getTimestamp()]);
        $legacy = rtrim(strtr(base64_encode($payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'))), '+/', '-_'), '=');

        $this->assertSame($legacy, ApprovalLinks::chatToken($instance, $step, $approver));
        $this->assertSame(
            ['instance_id' => $instance->id, 'step_id' => $step->id, 'approver_id' => $approver->id, 'action' => WorkflowActionType::Approve, 'expires' => now()->addHours(72)->getTimestamp()],
            ApprovalLinks::parseChatToken($legacy),
        );
    }

    public function test_chat_token_survives_an_application_key_rotation_like_signed_urls(): void
    {
        [$instance, $step, $approver] = $this->seat();
        $oldKey = (string) config('app.key');
        $token = ApprovalLinks::chatToken($instance, $step, $approver);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);
        $this->assertSame($instance->id, ApprovalLinks::parseChatToken($token)['instance_id']);

        config(['app.previous_keys' => []]);
        $this->expectException(InvalidArgumentException::class);
        ApprovalLinks::parseChatToken($token);
    }

    public function test_chat_and_email_links_share_one_lifetime(): void
    {
        $this->freezeTime();
        Setting::put('approvals.email_link_expiration_hours', '5');
        [$instance, $step, $approver] = $this->seat();

        parse_str((string) parse_url(ApprovalLinks::confirmationUrl($instance, $step, $approver), PHP_URL_QUERY), $query);

        $this->assertSame(now()->addHours(5)->getTimestamp(), (int) $query['expires']);
        $this->assertSame(now()->addHours(5)->getTimestamp(), ApprovalLinks::parseChatToken(ApprovalLinks::chatToken($instance, $step, $approver))['expires']);
    }

    public function test_backup_download_link_is_signed_for_its_named_lifetime(): void
    {
        $this->freezeTime();
        $snapshot = BackupSnapshot::factory()->create();

        $url = BackupDownloadLinks::download($snapshot);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringContainsString('/admin/backups/'.$snapshot->id.'/download', $url);
        $this->assertArrayHasKey('signature', $query);
        $this->assertSame(now()->addMinutes(BackupDownloadLinks::DOWNLOAD_MINUTES)->getTimestamp(), (int) $query['expires']);
    }

    /**
     * @return array{WorkflowInstance, WorkflowTemplateStep, User}
     */
    private function seat(): array
    {
        $step = WorkflowTemplateStep::factory()->create();
        $instance = WorkflowInstance::factory()->create(['current_step_id' => $step->id]);

        return [$instance, $step, User::factory()->create()];
    }
}
