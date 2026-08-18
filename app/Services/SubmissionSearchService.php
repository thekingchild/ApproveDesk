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
            ->where(function (Builder $search) use ($like, $statusLike): void {
                $search->where('reference_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('status', 'like', $statusLike)
                    ->orWhere('metadata', 'like', $like)
                    ->orWhereHas('submitter', fn (Builder $submitter) => $submitter->where('name', 'like', $like));
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

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
