<?php

namespace App\Services\Privacy;

use App\Actions\Privacy\LogSensitiveFieldAccessAction;
use App\Models\RequestFieldDefinition;
use App\Models\Submission;
use App\Models\User;
use App\Services\RequestFieldAccess;

final class ExportRedactor
{
    public const PLACEHOLDER = '[redacted]';

    public function __construct(
        private readonly RequestFieldAccess $access,
        private readonly LogSensitiveFieldAccessAction $logger,
    ) {}

    public function value(User $user, RequestFieldDefinition $field, Submission $submission, mixed $raw, string $context = 'export'): mixed
    {
        if (! $field->is_sensitive) {
            return $raw;
        }

        if (! $this->access->canView($user, $field, $submission)) {
            return self::PLACEHOLDER;
        }

        $this->logger->execute($user, $submission, $field, $context);

        return $raw;
    }
}
