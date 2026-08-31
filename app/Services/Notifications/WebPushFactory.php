<?php

namespace App\Services\Notifications;

use App\Support\OutboundPin;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushFactory
{
    /**
     * @param  array<string, string>  $vapid
     * @param  OutboundPin|list<OutboundPin>  $pins
     */
    public function make(array $vapid, OutboundPin|array $pins): WebPush
    {
        return new WebPush(['VAPID' => $vapid], [], 30, $this->clientOptions($pins));
    }

    /**
     * @param  OutboundPin|list<OutboundPin>  $pins
     * @return array{verify: true, allow_redirects: false, curl: array<int, list<string>>}
     */
    public function clientOptions(OutboundPin|array $pins): array
    {
        $entries = OutboundPin::curlResolveEntries($pins);

        if (! extension_loaded('curl') || ! function_exists('curl_init') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned web push delivery requires the cURL transport with CURLOPT_RESOLVE support.');
        }

        if ($entries === []) {
            throw new RuntimeException('Pinned web push delivery requires at least one validated destination.');
        }

        return [
            'verify' => true,
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => $entries,
            ],
        ];
    }
}
