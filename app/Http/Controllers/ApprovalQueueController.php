<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Services\ApproverWorkbench;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Previous / Next on the request page. The neighbour in the approver's queue
 * is worked out only when they ask for it, not on every render (RV-PERF-09).
 */
class ApprovalQueueController extends Controller
{
    public function __invoke(Request $request, Submission $submission, string $direction, ApproverWorkbench $workbench): RedirectResponse
    {
        $target = $workbench->neighbour($request->user(), $submission->id, $direction);

        if ($target === null) {
            return redirect()->route('approvals.inbox')
                ->with('status', __('There are no more requests waiting for your decision in that direction.'));
        }

        return redirect()->route('submissions.show', $target);
    }
}
