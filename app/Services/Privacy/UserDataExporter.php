<?php

namespace App\Services\Privacy;

use App\Models\Comment;
use App\Models\LoginEvent;
use App\Models\OutOfOffice;
use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use App\Models\UserSession;
use App\Models\WorkflowAction;

final class UserDataExporter
{
    /**
     * @return array<string, string>
     */
    public function files(User $user): array
    {
        $user->loadMissing(['department:id,name', 'roles', 'oauthIdentities']);

        $profile = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department?->name,
            'roles' => $user->roles->pluck('name')->all(),
            'is_active' => $user->is_active,
            'notification_frequency' => $user->notification_frequency->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'oauth_providers' => $user->oauthIdentities
                ->map(fn ($identity): string => $identity->provider->value)
                ->all(),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];

        $submissions = Submission::query()
            ->where('submitter_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'reference_number', 'title', 'status', 'type', 'created_at'])
            ->map(fn (Submission $submission): array => [
                'id' => $submission->id,
                'reference_number' => $submission->reference_number,
                'title' => $submission->title,
                'status' => $submission->status->value,
                'type' => $submission->type->value,
                'created_at' => $submission->created_at?->toIso8601String(),
            ])->all();

        $comments = Comment::query()
            ->where('author_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'commentable_type', 'commentable_id', 'body', 'created_at'])
            ->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'on' => class_basename((string) $comment->commentable_type),
                'record_id' => $comment->commentable_id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->all();

        $watchers = SubmissionWatcher::query()
            ->with('submission:id,reference_number,title')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (SubmissionWatcher $watcher): array => [
                'request' => $watcher->submission?->reference_number,
                'notify_comments' => $watcher->notify_comments,
                'notify_status' => $watcher->notify_status,
            ])->all();

        $ooo = $user->outOfOffice()
            ->orderByDesc('id')
            ->get()
            ->map(fn (OutOfOffice $period): array => [
                'starts_at' => $period->starts_at?->toIso8601String(),
                'ends_at' => $period->ends_at?->toIso8601String(),
                'fallback_user_id' => $period->fallback_user_id,
            ])->all();

        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (UserSession $session): array => [
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => $session->last_activity,
            ])->all();

        $logins = LoginEvent::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (LoginEvent $event): array => [
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'was_new_device' => $event->was_new_device,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all();

        $actions = WorkflowAction::query()
            ->where('actor_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'instance_id', 'action', 'created_at'])
            ->map(fn (WorkflowAction $action): array => [
                'id' => $action->id,
                'instance_id' => $action->instance_id,
                'action' => $action->action->value,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->all();

        return [
            'profile.json' => $this->encode($profile),
            'submissions.json' => $this->encode($submissions),
            'comments.json' => $this->encode($comments),
            'followers.json' => $this->encode($watchers),
            'out-of-office.json' => $this->encode($ooo),
            'sessions.json' => $this->encode($sessions),
            'login-events.json' => $this->encode($logins),
            'workflow-actions.json' => $this->encode($actions),
            'README.txt' => "ApproveDesk personal data export\nGenerated at ".now()->toIso8601String()."\nPasswords, recovery codes, API tokens, and connector secrets are not included.\n",
        ];
    }

    private function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
