<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $language
 * @property string $status
 * @property string|null $category
 * @property array<int, mixed>|null $components
 * @property int $placeholder_count
 */
class WhatsappMessageTemplate extends Model
{
    protected $fillable = ['name', 'language', 'status', 'category', 'components', 'placeholder_count', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'placeholder_count' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return strtoupper($this->status) === 'APPROVED';
    }
}
