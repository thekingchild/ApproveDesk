<?php

namespace App\DTOs;

final readonly class LoginDeviceCheck
{
    public function __construct(
        public string $fingerprint,
        /** The same device signed in within the last minute; nothing to record. */
        public bool $duplicate,
        /** A device this person has not used before (never true on a first sign-in). */
        public bool $newDevice,
    ) {}
}
