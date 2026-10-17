<?php

namespace Tests\Feature\Security;

use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RV-LAR-15 / RV-ARC-04: record keys a page acts on are set by the server only,
 * and the request page only edits its own comments.
 */
class LockedRecordKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, array{string, string}> */
    public static function adminPageKeys(): array
    {
        return [
            'users' => ['admin.users.user-form', 'editingUserId'],
            'users name review' => ['admin.users.name-changes', 'rejectingId'],
            'departments' => ['pages::admin.departments', 'editingDepartmentId'],
            'roles' => ['pages::admin.roles', 'editingRoleId'],
            'workflow templates' => ['admin.workflow-templates.editor', 'editingTemplateId'],
            'request types' => ['admin.request-types.builder', 'editingTypeId'],
            'request types draft flag (import was missing)' => ['admin.request-types.builder', 'localDraftDiscarded'],
            'webhooks' => ['pages::admin.webhooks', 'editingEndpointId'],
            'approvals inbox preview' => ['pages::approvals.inbox', 'previewInstanceId'],
        ];
    }

    #[DataProvider('adminPageKeys')]
    public function test_rv_lar_15_record_keys_cannot_be_set_from_the_browser(string $page, string $property): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $component = Livewire::actingAs($admin)->test($page)->assertOk();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set($property, 1);
    }

    public function test_rv_lar_15_request_page_comment_and_file_keys_are_locked(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        foreach (['submissions.comments' => 'editingCommentId', 'submissions.attachments' => 'previewFileId'] as $component => $property) {
            try {
                Livewire::actingAs($submitter)->test($component, ['submission' => $submission])->set($property, 1);
                $this->fail("{$property} should be locked.");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rv_lar_15_request_page_will_not_edit_a_comment_from_another_request(): void
    {
        $author = User::factory()->create()->assignRole('submitter');
        $mine = Submission::factory()->for($author, 'submitter')->create();
        $other = Submission::factory()->for($author, 'submitter')->create();
        $comment = Comment::factory()->create([
            'commentable_type' => Submission::class,
            'commentable_id' => $other->id,
            'author_id' => $author->id,
            'body' => 'On the other request.',
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($author)
            ->test('submissions.comments', ['submission' => $mine])
            ->call('startEdit', $comment->id);
    }
}
