<?php

namespace App\Contracts;

interface BackupTransport
{
    public function put(string $name, string $absolutePath): string;

    public function has(string $remoteId): bool;

    public function fetch(string $remoteId, string $destination): void;

    public function delete(string $remoteId): void;

    public function test(): bool;
}
