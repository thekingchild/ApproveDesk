<?php

namespace App\Services;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SubmissionSearchService
{
    /**
     * Search submission fields without allowing the search index to bypass
     * SubmissionPolicy. Results are streamed newest-first and collected only
     * after the policy authorizes the current user.
     *
     * @return Collection<int, Submission>
     */
    public function search(User $user, string $query, int $limit = 8): Collection
    {
        $term = Str::of($query)->squish()->toString();

        if (Str::length($term) < 2 || $limit < 1) {
            return new Collection;
        }

        $statusTerm = Str::of($term)->lower()->replace(' ', '_')->toString();
        $like = '%'.$this->escapeLike($term).'%';
        $statusLike = '%'.$statusTerm.'%';
        $results = new Collection;

        $candidates = Submission::query()
            ->with(['submitter', 'department', 'workflowInstance.currentStep'])
            ->where(function (Builder $search) use ($user, $like, $statusLike): void {
                $search->where('reference_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('status', 'like', $statusLike)
                    ->orWhere('metadata', 'like', $like)
                    ->orWhereHas('submitter', fn (Builder $submitter) => $submitter->where('name', 'like', $like))
                    ->orWhereHas('fieldValues', fn (Builder $values) => $this->matchingFieldValue($values, $like, false));

                if (app(RequestFieldAccess::class)->canDiscoverSensitive($user)) {
                    $search->orWhereHas('fieldValues', fn (Builder $values) => $this->matchingFieldValue($values, $like, true));
                } else {
                    $search->orWhere(function (Builder $owned) use ($user, $like): void {
                        $owned->where('submitter_id', $user->id)
                            ->whereHas('fieldValues', fn (Builder $values) => $this->matchingFieldValue($values, $like, true));
                    });
                }
            })
            ->latest('updated_at')
            ->latest('id')
            ->cursor();

        foreach ($candidates as $submission) {
            if (Gate::forUser($user)->allows('view', $submission)) {
                $results->push($submission);
            }

            if ($results->count() >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function matchingFieldValue(Builder $query, string $like, bool $sensitive): void
    {
        $query->whereHas('field', fn (Builder $field) => $field
            ->where('is_searchable', true)
            ->where('is_sensitive', $sensitive))
            ->where(function (Builder $value) use ($like): void {
                $value->where('string_value', 'like', $like)
                    ->orWhere('text_value', 'like', $like)
                    ->orWhere('decimal_value', 'like', $like)
                    ->orWhere('date_value', 'like', $like)
                    ->orWhere('datetime_value', 'like', $like)
                    ->orWhere('json_value', 'like', $like)
                    ->orWhereHas('userValue', fn (Builder $user) => $user->where('name', 'like', $like))
                    ->orWhereHas('departmentValue', fn (Builder $department) => $department->where('name', 'like', $like));
            });
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
