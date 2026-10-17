<?php

namespace App\Models;

use Database\Factories\WhatsappNotificationMapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    /** @use HasFactory<WhatsappNotificationMapFactory> */
    use HasFactory;

    protected $fillable = ['kind', 'template_name', 'language', 'bindings', 'is_healthy'];

    protected function casts(): array
    {
        return [
            'bindings' => 'array',
            'is_healthy' => 'boolean',
        ];
    }
}
