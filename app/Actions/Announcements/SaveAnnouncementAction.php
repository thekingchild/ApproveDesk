<?php

namespace App\Actions\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveAnnouncementAction
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * @param  array{title: string, message: string, audience: string, role_ids?: list<int|string>, display_mode: string}  $data
     */
    public function execute(?Announcement $announcement, array $data, User $actor): Announcement
    {
        Gate::forUser($actor)->authorize($announcement ? 'update' : 'create', $announcement ?? Announcement::class);
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:50000'],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'display_mode' => ['required', Rule::enum(AnnouncementDisplayMode::class)],
        ])->validate();
        if ($validated['audience'] === AnnouncementAudience::Roles->value && empty($validated['role_ids'])) {
            throw ValidationException::withMessages(['role_ids' => 'Select at least one role.']);
        }
        $message = $this->sanitizer->sanitize($validated['message']);
        if (trim(strip_tags($message)) === '') {
            throw ValidationException::withMessages(['message' => 'The message must contain readable text.']);
        }

        $attributes = [
            'title' => $validated['title'],
            'message' => $message,
            'audience' => $validated['audience'],
            'role_ids' => $validated['audience'] === AnnouncementAudience::All->value ? null : array_values($validated['role_ids']),
            'display_mode' => $validated['display_mode'],
        ];

        return DB::transaction(function () use ($announcement, $actor, $attributes): Announcement {
            if (! $announcement) {
                $created = new Announcement(['creator_id' => $actor->id, 'status' => AnnouncementStatus::Draft]);
                $created->fill($attributes)->save();

                return $created->fresh();
            }

            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            $locked->fill($attributes);
            $republish = $locked->status === AnnouncementStatus::Published && $locked->isDirty([
                'title', 'message', 'audience', 'role_ids', 'display_mode',
            ]);
            if ($republish) {
                $locked->published_at = now();
                $locked->receipts()->delete();
            }
            $locked->save();

            return $locked->fresh();
        });
    }
}
