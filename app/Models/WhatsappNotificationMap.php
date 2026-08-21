<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $kind
 * @property string $template_name
 * @property string $language
 * @property array<string, string> $bindings
 * @property bool $is_healthy
 */
class WhatsappNotificationMap extends Model
{
    protected $fillable = ['kind', 'template_name', 'language', 'bindings', 'is_healthy'];

    protected function casts(): array
    {
        return [
            'bindings' => 'array',
            'is_healthy' => 'boolean',
        ];
    }
}
