<?php

namespace App\DTOs;

final readonly class UserData
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?int $departmentId,
        public array $roles,
        public bool $isActive,
        public ?string $password = null,
        public ?int $managerId = null,
    ) {}
}
