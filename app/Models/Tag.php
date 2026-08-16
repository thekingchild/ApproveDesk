<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            $tag->slug = $tag->slug ?? Str::slug($tag->name);
        });
    }

    /**
     * @return MorphToMany<Submission, $this>
     */
    public function submissions(): MorphToMany
    {
        return $this->morphedByMany(Submission::class, 'taggable');
    }
}
