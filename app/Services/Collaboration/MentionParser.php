<?php

namespace App\Services\Collaboration;

use App\Models\User;
use Illuminate\Support\Collection;

final class MentionParser
{
    public const TOKEN_PATTERN = '/@\{(\d+)\}/';

    /**
     * @param  array<int, int>  $explicitIds
     * @return list<int>
     */
    public function userIds(string $body, array $explicitIds = []): array
    {
        preg_match_all(self::TOKEN_PATTERN, $body, $matches);

        $ids = array_map('intval', $matches[1] ?? []);
        $ids = array_merge($ids, array_map('intval', $explicitIds));
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->assignable()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $users
     */
    public function display(string $body, Collection|array $users): string
    {
        $names = collect($users)->mapWithKeys(
            fn (User $user): array => [$user->id => '@'.$user->name],
        );

        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $match) use ($names): string {
                $id = (int) $match[1];

                return $names->get($id, $match[0]);
            },
            $body,
        );
    }

    public function appendToken(string $body, int $userId): string
    {
        $token = '@{'.$userId.'}';
        if (str_contains($body, $token)) {
            return $body;
        }

        $body = rtrim($body);

        return ($body === '' ? '' : $body.' ').$token;
    }
}
