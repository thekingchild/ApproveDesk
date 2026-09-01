<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope', 'current_value'])]
class ApprovalReferenceCounter extends Model
{
    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }
}
