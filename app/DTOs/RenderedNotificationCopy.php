<?php

namespace App\DTOs;

final readonly class RenderedNotificationCopy
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public string $body,
        public array $lines,
        public ?string $actionUrl,
        public ?string $actionLabel,
    ) {}
}
