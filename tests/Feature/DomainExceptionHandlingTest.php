<?php

namespace Tests\Feature;

use App\Exceptions\CollaborationException;
use App\Exceptions\DomainException;
use App\Exceptions\WorkflowException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/** RV-ARC-19: refused domain rules reach people as readable, translated messages in one place. */
class DomainExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rv_arc_19_user_messages_never_show_emails_or_internal_brackets(): void
    {
        $exceptions = [
            WorkflowException::actorNotAssigned('ada@example.com', 'Finance review'),
            WorkflowException::duplicateAction('ada@example.com'),
            WorkflowException::instanceNotActive('completed'),
            WorkflowException::unknownOutcome('pay_now'),
            WorkflowException::invalidQuorum('Board', 4, 2),
            WorkflowException::noTemplateFor('general'),
            WorkflowException::commentRequired('request_information'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(DomainException::class, $exception);
            $this->assertStringNotContainsString('@', $exception->userMessage());
            $this->assertStringNotContainsString('[', $exception->userMessage());
        }

        // The developer message keeps the detail for logs and tests.
        $this->assertStringContainsString('ada@example.com', WorkflowException::duplicateAction('ada@example.com')->getMessage());
        $this->assertSame('You have already decided this step.', WorkflowException::duplicateAction('ada@example.com')->userMessage());
        $this->assertSame('Add a comment to request information.', WorkflowException::commentRequired('request_information')->userMessage());
        $this->assertInstanceOf(DomainException::class, CollaborationException::invalidReply());
    }

    public function test_rv_arc_19_message_for_falls_back_to_the_plain_message(): void
    {
        $this->assertSame('You have already decided this step.', DomainException::messageFor(WorkflowException::duplicateAction('a@b.c')));
        $this->assertSame('Plain', DomainException::messageFor(new \InvalidArgumentException('Plain')));
    }

    public function test_rv_arc_19_an_uncaught_refusal_in_a_livewire_action_becomes_a_message(): void
    {
        $component = new class extends Component
        {
            public function decide(): void
            {
                throw WorkflowException::duplicateAction('ada@example.com');
            }

            public function render(): string
            {
                return '<div>@error(\'domain\') <p>{{ $message }}</p> @enderror</div>';
            }
        };

        Livewire::actingAs(User::factory()->create())
            ->test($component::class)
            ->call('decide')
            ->assertHasErrors('domain')
            ->assertSee('You have already decided this step.')
            ->assertDontSee('ada@example.com');
    }

    public function test_rv_arc_19_api_and_form_posts_get_the_user_message(): void
    {
        Route::middleware('web')->post('/_rv-arc-19', fn () => throw WorkflowException::duplicateAction('ada@example.com'));

        $this->postJson('/_rv-arc-19')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'You have already decided this step.']);

        $this->from('/somewhere')->post('/_rv-arc-19')
            ->assertRedirect('/somewhere')
            ->assertSessionHasErrors(['domain' => 'You have already decided this step.']);
    }
}
