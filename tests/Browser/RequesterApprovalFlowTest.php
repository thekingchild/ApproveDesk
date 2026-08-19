<?php

namespace Tests\Browser;

use App\Enums\SubmissionStatus;
use App\Models\RequestType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RequesterApprovalFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_requester_can_save_reopen_and_submit_a_draft(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        $this->browse(function (Browser $browser) use ($submitter): void {
            $browser->loginAs($submitter)
                ->visitRoute('submissions.create')
                ->waitFor('@request-title')
                ->type('@request-title', 'Browser-tested purchase request')
                ->press('@save-draft')
                ->waitForRoute('submissions.index')
                ->assertSee('Browser-tested purchase request');

            $draft = Submission::query()->where('title', 'Browser-tested purchase request')->firstOrFail();
            $this->assertSame(SubmissionStatus::Draft, $draft->status);

            $browser->visitRoute('submissions.edit', $draft)
                ->waitFor('@request-title')
                ->scrollIntoView('@continue')
                ->click('@continue')
                ->waitForText('Attachments')
                ->pause(300)
                ->click('@continue')
                ->waitFor('@submit-request')
                ->pause(300)
                ->click('@submit-request')
                ->waitForRoute('submissions.show', $draft)
                ->assertSee('in review');

            $this->assertSame(SubmissionStatus::InReview, $draft->fresh()->status);
        });
    }

    public function test_admin_builds_and_publishes_a_custom_form_then_requester_saves_reopens_and_submits_it(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();

        $this->browse(function (Browser $browser) use ($admin, $submitter): void {
            $browser->loginAs($admin)
                ->visitRoute('admin.request-types.index')
                ->waitFor('@create-request-type')
                ->click('@create-request-type')
                ->waitFor('@request-type-name')
                ->type('@request-type-name', 'Browser Leave Request')
                ->type('@field-label-0', 'Leave reason')
                ->type('@field-key-0', 'leave_reason')
                ->check('@field-required-0')
                ->click('@publish-request-type')
                ->waitForText('Request type published.');

            $requestType = RequestType::query()->where('slug', 'browser-leave-request')->firstOrFail();
            $this->assertNotNull($requestType->publishedVersion);

            $browser->loginAs($submitter)
                ->visitRoute('submissions.custom.create', $requestType)
                ->waitFor('@custom-field-leave_reason')
                ->type('@custom-field-leave_reason', 'Family holiday')
                ->click('@save-custom-draft')
                ->waitForText('Family holiday')
                ->assertSee('Family holiday');

            $draft = Submission::query()
                ->where('submitter_id', $submitter->id)
                ->where('request_type_version_id', $requestType->publishedVersion->id)
                ->sole();
            $this->assertSame(SubmissionStatus::Draft, $draft->status);

            $browser->visitRoute('submissions.custom.edit', $draft)
                ->waitFor('@custom-field-leave_reason')
                ->assertInputValue('@custom-field-leave_reason', 'Family holiday')
                ->click('@review-custom-request')
                ->waitForText('Review summary')
                ->assertSee('Family holiday')
                ->click('@submit-custom-request')
                ->waitForRoute('submissions.show', $draft)
                ->assertSee('in review');

            $this->assertSame(SubmissionStatus::InReview, $draft->fresh()->status);
        });
    }

    public function test_approver_modal_requires_a_comment_and_keyboard_order_is_logical(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['title' => 'Decision modal request']);
        app(WorkflowEngine::class)->initialize($submission);

        $this->browse(function (Browser $browser) use ($approver, $submission, $submitter): void {
            $browser->loginAs($approver)
                ->visitRoute('submissions.show', $submission)
                ->waitFor('[data-test="reject-modal-trigger"]')
                ->click('[data-test="reject-modal-trigger"]')
                ->waitForText('Reject this submission?')
                ->press('Confirm rejection')
                ->waitForText('A comment is required for this action.')
                ->type('@reject-comment', 'Required browser-test reason.')
                ->press('Confirm rejection')
                ->waitForText('rejected');

            $this->assertSame(SubmissionStatus::Rejected, $submission->fresh()->status);

            $browser->loginAs($submitter)->visitRoute('submissions.create')->waitFor('@request-title');
            $focusOrder = $browser->script(<<<'JS'
                return [...document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')]
                    .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length)
                    .map(element => element.getAttribute('dusk') || element.getAttribute('href') || element.textContent.trim())
            JS)[0];

            $this->assertIsArray($focusOrder);
            $this->assertSame('#main-content', $focusOrder[0]);
            $titleIndex = array_search('request-title', $focusOrder, true);
            $saveIndex = array_search('save-draft', $focusOrder, true);
            $continueIndex = array_search('continue', $focusOrder, true);
            $this->assertIsInt($titleIndex);
            $this->assertIsInt($saveIndex);
            $this->assertIsInt($continueIndex);
            $this->assertLessThan($saveIndex, $titleIndex);
            $this->assertLessThan($continueIndex, $titleIndex);
        });
    }
}
