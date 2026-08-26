<?php

namespace App\DTOs;

final readonly class UserData
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone,
        public ?int $departmentId,
        public array $roles,
        public bool $isActive,
        public ?string $password = null,
        public ?int $managerId = null,
    ) {}
}
