<?php

namespace App\Http\Controllers;

use App\Actions\Approvals\ApproveSubmissionFromChatAction;
use App\Enums\NotificationChannelKey;
use App\Exceptions\WorkflowException;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Throwable;

class SlackInteractionsController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings, ApproveSubmissionFromChatAction $approve): Response
    {
        $raw = $request->getContent();
        $secret = $settings->credentials(NotificationChannelKey::Slack)['signing_secret'] ?? '';
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp');
        $signature = (string) $request->header('X-Slack-Signature');

        abort_unless($secret !== '' && $timestamp !== '' && $signature !== '', 403);
        abort_unless(abs(time() - (int) $timestamp) < 300, 403);

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$raw, $secret);
        abort_unless(hash_equals($expected, $signature), 403);

        $payload = json_decode((string) $request->input('payload', $raw), true);
        if (! is_array($payload)) {
            return response('ignored', 200);
        }

        if (($payload['type'] ?? '') === 'url_verification') {
            return response((string) ($payload['challenge'] ?? ''), 200);
        }

        $token = data_get($payload, 'actions.0.value');
        if (! is_string($token) || $token === '') {
            return response('ok', 200);
        }

        try {
            $approve->execute($token);
        } catch (InvalidArgumentException|WorkflowException) {
            return response('This approval is no longer current.', 200);
        } catch (Throwable) {
            return response('Unable to record that approval.', 200);
        }

        return response('Approved in ApproveDesk.', 200);
    }
}
