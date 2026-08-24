<?php

namespace App\Actions\Operations;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

final class ReplayFailedJobAction
{
    public function execute(string $uuid): void
    {
        $failer = app('queue.failer');
        $job = $failer->find($uuid);

        if ($job === null) {
            throw new InvalidArgumentException('That failed job is no longer on the list.');
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }
}
