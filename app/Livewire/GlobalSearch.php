<?php

namespace App\Livewire;

use App\Models\Submission;
use App\Services\SubmissionSearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';

    /** @return Collection<int, Submission> */
    #[Computed]
    public function results(): Collection
    {
        return app(SubmissionSearchService::class)->search(Auth::user(), $this->query);
    }

    public function clear(): void
    {
        $this->query = '';
        unset($this->results);
    }

    public function render(): mixed
    {
        return view('livewire.global-search');
    }
}
