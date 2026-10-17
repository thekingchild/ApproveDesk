<?php

namespace App\Http\Controllers;

use App\Actions\Approvals\ApproveSubmissionFromEmailAction;
use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use App\Support\EmailApprovalLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailApprovalController extends Controller
{
    public function show(
        Request $request,
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
    ): View {
        $this->authorizeLink($instance, $step, $approver);

        $expiresAt = Carbon::createFromTimestamp((int) $request->query('expires'));

        return view('approvals.email-confirm', [
            'submission' => $instance->submission()->with('submitter:id,name')->firstOrFail(),
            'step' => $step,
            'approver' => $approver,
            'approvalUrl' => EmailApprovalLinks::approvalUrl($instance, $step, $approver, $expiresAt),
            'expiresAt' => $expiresAt,
        ]);
    }

    public function approve(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        ApproveSubmissionFromEmailAction $approveSubmission,
    ): RedirectResponse {
        $this->authorizeLink($instance, $step, $approver);

        try {
            $approveSubmission->execute($instance, $approver, $step->id);
        } catch (WorkflowException $exception) {
            return redirect()->route('home')->withErrors(['approval' => $exception->getMessage()]);
        }

        return redirect()->route('home')->with('status', __('The submission was approved successfully.'));
    }

    private function authorizeLink(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
    ): void {
        abort_unless($instance->current_step_id === $step->id, 410, 'This approval link is no longer current.');
        abort_if(
            Gate::forUser($approver)->denies('approveViaEmail', $instance->submission),
            403,
            __('This request is not waiting for your decision. Open it in ApproveDesk to see where it stands.'),
        );
    }
}
