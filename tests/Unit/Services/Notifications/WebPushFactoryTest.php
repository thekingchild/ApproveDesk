<?php

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\WebPushFactory;
use App\Support\OutboundPin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Tests\TestCase;

class WebPushFactoryTest extends TestCase
{
    public function test_factory_builds_web_push_with_every_pin_and_hardened_client_options(): void
    {
        $pins = [
            new OutboundPin(
                'https://push.example.test/push',
                'push.example.test',
                443,
                '93.184.216.34',
                ['93.184.216.34', '1.1.1.1'],
            ),
            new OutboundPin(
                'https://updates.example.test/push',
                'updates.example.test',
                443,
                '8.8.8.8',
                ['8.8.8.8'],
            ),
        ];
        $keys = VAPID::createVapidKeys();

        $webPush = (new WebPushFactory)->make([
            'subject' => 'mailto:security@example.test',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ], $pins);

        $this->assertInstanceOf(WebPush::class, $webPush);

        $client = (new \ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);
        $this->assertTrue($client->getConfig('verify'));
        $this->assertFalse($client->getConfig('allow_redirects'));
        $this->assertSame([
            'push.example.test:443:93.184.216.34',
            'push.example.test:443:1.1.1.1',
            'updates.example.test:443:8.8.8.8',
        ], $client->getConfig('curl')[CURLOPT_RESOLVE]);
    }

    public function test_factory_options_do_not_follow_redirects_to_private_targets(): void
    {
        $targets = [
            'https://127.0.0.1/secret',
            'https://10.0.0.1/secret',
            'https://169.254.1.1/secret',
            'https://[::1]/secret',
            'https://[fd00::1]/secret',
            'https://[fe80::1]/secret',
        ];
        $mock = new MockHandler(array_map(
            static fn (string $target): Response => new Response(302, ['Location' => $target]),
            $targets,
        ));
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $pin = new OutboundPin(
            'https://push.example.test/start',
            'push.example.test',
            443,
            '93.184.216.34',
            ['93.184.216.34'],
        );
        $options = (new WebPushFactory)->clientOptions($pin);
        $client = new Client([...$options, 'handler' => $stack]);

        foreach (array_keys($targets) as $index) {
            $response = $client->get("https://push.example.test/start/{$index}");
            $this->assertSame(302, $response->getStatusCode());
        }

        $this->assertCount(count($targets), $history, 'Each redirect response must result in exactly one request.');
        foreach ($history as $transaction) {
            $this->assertSame('push.example.test', $transaction['request']->getUri()->getHost());
        }
    }

    public function test_factory_fails_closed_without_pins(): void
    {
        $this->expectException(RuntimeException::class);
        (new WebPushFactory)->clientOptions([]);
    }
}
