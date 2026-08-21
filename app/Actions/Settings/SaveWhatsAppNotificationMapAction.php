<?php

namespace App\Actions\Settings;

use App\Enums\NotificationKind;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappNotificationMap;
use InvalidArgumentException;

final class SaveWhatsAppNotificationMapAction
{
    /**
     * @param  array<string, string>  $bindings
     */
    public function execute(NotificationKind $kind, string $templateName, string $language, array $bindings): WhatsappNotificationMap
    {
        $catalog = WhatsappMessageTemplate::query()
            ->where('name', $templateName)
            ->where('language', $language)
            ->first();

        if ($catalog === null || ! $catalog->isApproved()) {
            throw new InvalidArgumentException('Choose an approved WhatsApp template.');
        }

        $required = $kind->whatsappSlots();
        foreach ($required as $slot) {
            if (! isset($bindings[$slot]) || trim((string) $bindings[$slot]) === '') {
                throw new InvalidArgumentException('Bind every required field, including '.$slot.'.');
            }
        }

        if (count($bindings) > $catalog->placeholder_count && $catalog->placeholder_count > 0) {
            throw new InvalidArgumentException('This template does not have enough placeholders for the mapping.');
        }

        return WhatsappNotificationMap::query()->updateOrCreate(
            ['kind' => $kind->value],
            [
                'template_name' => $templateName,
                'language' => $language,
                'bindings' => $bindings,
                'is_healthy' => true,
            ],
        );
    }
}
