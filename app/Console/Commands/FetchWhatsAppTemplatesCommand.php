<?php

namespace App\Console\Commands;

use App\Actions\Settings\FetchWhatsAppTemplatesAction;
use Illuminate\Console\Command;
use Throwable;

class FetchWhatsAppTemplatesCommand extends Command
{
    protected $signature = 'approvedesk:fetch-whatsapp-templates';

    protected $description = 'Refresh the cached WhatsApp Business Platform template catalog';

    public function handle(FetchWhatsAppTemplatesAction $fetch): int
    {
        try {
            $count = $fetch->execute();
            $this->info('Cached '.$count.' WhatsApp templates.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->warn($exception->getMessage());

            return self::SUCCESS;
        }
    }
}
