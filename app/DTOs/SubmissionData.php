<?php

namespace App\DTOs;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionType;

/**
 * Validated payload for creating or updating a submission.
 */
final readonly class SubmissionData
{
    public function __construct(
        public SubmissionType $type,
        public string $title,
        public ?string $body,
        public SubmissionPriority $priority = SubmissionPriority::Normal,
        public ?int $departmentId = null,
        public ?string $dueDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            type: SubmissionType::from($validated['type']),
            title: $validated['title'],
            body: $validated['body'] ?? null,
            priority: SubmissionPriority::from($validated['priority'] ?? 'normal'),
            departmentId: isset($validated['department_id']) ? (int) $validated['department_id'] : null,
            dueDate: $validated['due_date'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'priority' => $this->priority,
            'department_id' => $this->departmentId,
            'due_date' => $this->dueDate,
        ];
    }
}
