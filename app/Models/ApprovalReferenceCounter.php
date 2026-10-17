<?php

namespace App\Models;

use Database\Factories\ApprovalReferenceCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope', 'current_value'])]
class ApprovalReferenceCounter extends Model
{
    /** @use HasFactory<ApprovalReferenceCounterFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }
}
