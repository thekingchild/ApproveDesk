<?php

namespace App\Console\Commands;

use App\Actions\Operations\ReplayDeadLetterAction;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ReplayDeadLetterCommand extends Command
{
    protected $signature = 'ops:replay {type : webhook, integration, notification, or job} {id : Delivery id or failed-job uuid}';

    protected $description = 'Safely replay one failed delivery or failed job. Does not change workflow history.';

    public function handle(ReplayDeadLetterAction $replay): int
    {
        try {
            $replay->execute((string) $this->argument('type'), (string) $this->argument('id'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Replay queued.');

        return self::SUCCESS;
    }
}
