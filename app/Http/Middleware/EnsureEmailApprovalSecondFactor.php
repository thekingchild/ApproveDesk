<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\EmailApprovalLinks;
use App\Support\SecondFactorAssurance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureEmailApprovalSecondFactor
{
    public function __construct(private SecondFactorAssurance $assurance) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! EmailApprovalLinks::requiresSecondFactor()) {
            return $next($request);
        }

        $approver = $request->route('approver');

        if (! $approver instanceof User) {
            abort(403);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            $this->record('authentication_required', $approver);

            return redirect()
                ->guest(route('login'))
                ->with('approval_second_factor_required', true);
        }

        if (! $user->is($approver)) {
            $this->record('wrong_account', $approver, $user);
            abort(403, __('This approval link belongs to another account.'));
        }

        if (! $user->hasSatisfiedSecondFactor()) {
            $this->preserveIntendedUrl($request);
            $request->session()->put([
                'approval_second_factor_enrollment_required' => true,
                'approval_second_factor_intended' => $request->fullUrl(),
            ]);
            $this->record('enrollment_required', $approver, $user);

            return redirect()->route('security.edit');
        }

        if ($this->assurance->satisfied($request, $user)) {
            $request->session()->forget([
                'approval_second_factor_enrollment_required',
                'approval_second_factor_intended',
            ]);

            return $next($request);
        }

        $this->record('assurance_required', $approver, $user);

        $intended = $request->fullUrl();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', $intended);

        return redirect()
            ->route('login')
            ->with('approval_second_factor_required', true);
    }

    private function preserveIntendedUrl(Request $request): void
    {
        $request->session()->put('url.intended', $request->fullUrl());
    }

    private function record(string $result, User $approver, ?User $user = null): void
    {
        Log::notice('Signed approval second-factor gate', [
            'result' => $result,
            'approver_id' => $approver->getKey(),
            'authenticated_user_id' => $user?->getKey(),
        ]);
    }
}
