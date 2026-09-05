<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplayNameChange extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'pending_user_id', 'proposed_name', 'normalized_name', 'status', 'decided_by', 'decided_at', 'reason'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decisionActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
