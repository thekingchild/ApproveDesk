<?php

namespace App\Models;

use App\Enums\RequestTypeVersionStatus;
use Database\Factories\RequestTypeVersionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_type_id
 * @property int $version
 * @property RequestTypeVersionStatus $status
 * @property string|null $schema_hash
 * @property int|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RequestType $requestType
 * @property-read Collection<int, RequestFieldDefinition> $fields
 */
class RequestTypeVersion extends Model
{
    /** @use HasFactory<RequestTypeVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'request_type_id', 'version', 'status', 'schema_hash', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => RequestTypeVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === RequestTypeVersionStatus::Published->value) {
                throw new DomainException('Published request type versions are immutable.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->status === RequestTypeVersionStatus::Published) {
                throw new DomainException('Published request type versions cannot be deleted.');
            }
        });
    }

    public static function general(): self
    {
        return self::query()
            ->where('status', RequestTypeVersionStatus::Published)
            ->whereHas('requestType', fn ($query) => $query->where('slug', RequestType::GENERAL_SLUG))
            ->firstOrFail();
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<RequestFieldDefinition, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(RequestFieldDefinition::class)->orderBy('position');
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
