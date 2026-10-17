<?php

namespace App\Http\Controllers;

use App\Actions\Approvals\ApproveSubmissionFromChatAction;
use App\Enums\NotificationChannelKey;
use App\Exceptions\DomainException;
use App\Exceptions\WorkflowException;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class TeamsActionsController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings, ApproveSubmissionFromChatAction $approve): JsonResponse
    {
        abort_unless($settings->isEnabled(NotificationChannelKey::Teams) && $settings->hasCredentials(NotificationChannelKey::Teams), 404);

        $token = $request->input('token');
        if (! is_string($token) || $token === '') {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $approve->execute($token);
        } catch (InvalidArgumentException|WorkflowException $exception) {
            return response()->json(['status' => 'stale', 'message' => DomainException::messageFor($exception)]);
        } catch (Throwable) {
            return response()->json(['status' => 'error']);
        }

        return response()->json(['status' => 'approved']);
    }
}
