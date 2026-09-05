<?php

namespace App\Http\Controllers;

use App\Mail\SystemTestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestSystemMailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Gate::authorize('system_settings.manage');
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        try {
            Mail::to($validated['email'])->queue(new SystemTestMail);
        } catch (Throwable $exception) {
            Log::warning('System test email could not be queued.', ['exception_class' => $exception::class]);

            return back()->withErrors(['test_mail' => __('The test email could not be sent. Check the saved settings and application log.')]);
        }

        return back()->with('status', __('Test email queued.'));
    }
}
