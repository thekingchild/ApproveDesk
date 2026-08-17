<?php

namespace App\DTOs;

final readonly class DepartmentData
{
    public function __construct(
        public string $name,
        public ?int $parentId,
        public ?int $managerId,
    ) {}
}
