<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Actions\Privacy\AnonymizeUserAction;
use App\Enums\NotificationFrequency;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $teams_user_id
 * @property string|null $slack_user_id
 * @property string|null $telegram_user_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $department_id
 * @property int|null $manager_id
 * @property bool $is_active
 * @property NotificationFrequency $notification_frequency
 * @property Carbon|null $last_notification_digest_at
 * @property string|null $avatar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Department|null $department
 * @property-read User|null $manager
 * @property-read Collection<int, Submission> $submissions
 * @property-read Collection<int, OAuthIdentity> $oauthIdentities
 * @property-read Collection<int, SubmissionSavedView> $submissionSavedViews
 * @property-read Collection<int, OutOfOffice> $outOfOffice
 * @property-read Collection<int, SubmissionWatcher> $watchedSubmissions
 */
#[Fillable(['name', 'email', 'phone', 'password', 'department_id', 'manager_id', 'is_active', 'email_verified_at', 'notification_frequency', 'last_notification_digest_at', 'avatar'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'notification_frequency' => 'immediate',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'department_id' => 'integer',
            'manager_id' => 'integer',
            'is_active' => 'boolean',
            'notification_frequency' => NotificationFrequency::class,
            'last_notification_digest_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'submitter_id');
    }

    /** @return HasMany<OAuthIdentity, $this> */
    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OAuthIdentity::class);
    }

    /** @return HasMany<SubmissionSavedView, $this> */
    public function submissionSavedViews(): HasMany
    {
        return $this->hasMany(SubmissionSavedView::class);
    }

    /** @return HasMany<OutOfOffice, $this> */
    public function outOfOffice(): HasMany
    {
        return $this->hasMany(OutOfOffice::class);
    }

    /** @return HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** @return HasMany<SubmissionWatcher, $this> */
    public function watchedSubmissions(): HasMany
    {
        return $this->hasMany(SubmissionWatcher::class);
    }

    /** @return HasMany<AuditorAccess, $this> */
    public function auditorAccesses(): HasMany
    {
        return $this->hasMany(AuditorAccess::class);
    }

    /** @return HasMany<LoginEvent, $this> */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function isAnonymized(): bool
    {
        return str_ends_with($this->email, '@invalid.local')
            && str_starts_with($this->email, 'anonymized-');
    }

    public function hasPreservedAuditEvidence(): bool
    {
        return AnonymizeUserAction::hasPreservedAuditEvidence($this);
    }

    public function hasActiveAuditorAccess(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->auditorAccesses()->active($at)->exists();
    }

    public function isReadOnlyAuditor(): bool
    {
        return $this->hasRole('auditor')
            && ! $this->can('settings.manage')
            && ! $this->can('approvals.act');
    }

    public function hasSatisfiedSecondFactor(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication()
            || $this->passkeys()->exists();
    }

    public function isOutOfOffice(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->outOfOffice()->active($at)->exists();
    }

    public function activeOutOfOffice(?CarbonInterface $at = null): ?OutOfOffice
    {
        $at ??= now();

        return $this->outOfOffice()->active($at)->latest('id')->first();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
