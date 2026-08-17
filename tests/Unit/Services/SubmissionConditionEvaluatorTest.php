<?php

namespace Tests\Unit\Services;

use App\Enums\SubmissionPriority;
use App\Models\Submission;
use App\Services\SubmissionConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SubmissionConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_supports_all_and_any_rule_groups(): void
    {
        $submission = Submission::factory()->priority(SubmissionPriority::Urgent)->create([
            'title' => 'Quarterly procurement request',
            'metadata' => ['amount' => 1500, 'region' => 'west'],
        ]);
        $evaluator = app(SubmissionConditionEvaluator::class);

        $this->assertTrue($evaluator->evaluate($submission, [
            'match' => 'all',
            'rules' => [
                ['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent'],
                ['field' => 'metadata.amount', 'operator' => 'gt', 'value' => 1000],
            ],
        ]));
        $this->assertTrue($evaluator->evaluate($submission, [
            'match' => 'any',
            'rules' => [
                ['field' => 'metadata.region', 'operator' => 'equals', 'value' => 'east'],
                ['field' => 'title', 'operator' => 'contains', 'value' => 'PROCUREMENT'],
            ],
        ]));
    }

    public function test_unknown_fields_and_operators_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SubmissionConditionEvaluator::class)->evaluate(Submission::factory()->create(), [
            'rules' => [['field' => 'status', 'operator' => 'execute', 'value' => 'approved']],
        ]);
    }
}
