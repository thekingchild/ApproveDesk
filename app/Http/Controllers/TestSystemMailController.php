<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestSystemMailRequest;
use App\Mail\SystemTestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestSystemMailController extends Controller
{
    public function __invoke(TestSystemMailRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            Mail::to($validated['email'])->queue(new SystemTestMail);
        } catch (Throwable $exception) {
            Log::warning('System test email could not be queued.', ['exception_class' => $exception::class]);

            return back()->withErrors(['test_mail' => __('The test email could not be sent. Check the saved settings and application log.')]);
        }

        return back()->with('status', __('Test email queued.'));
    }
}
