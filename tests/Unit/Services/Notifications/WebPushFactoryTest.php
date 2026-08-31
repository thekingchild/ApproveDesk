<?php

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\WebPushFactory;
use App\Support\OutboundPin;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

class WebPushFactoryTest extends TestCase
{
    public function test_factory_builds_web_push_with_pinned_client_options(): void
    {
        $this->assertTrue(
            class_exists(WebPushFactory::class),
            'App\Services\Notifications\WebPushFactory must exist and build Minishlink WebPush with pinned HTTP client options.',
        );

        $pin = new OutboundPin(
            'https://push.example.test/push',
            'push.example.test',
            443,
            '93.184.216.34',
            ['93.184.216.34'],
        );

        $webPush = (new WebPushFactory)->make([], $pin);

        $this->assertInstanceOf(WebPush::class, $webPush);

        $client = (new \ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);
        $this->assertTrue($client->getConfig('verify'));
        $this->assertFalse($client->getConfig('allow_redirects'));

        $curl = $client->getConfig('curl');
        $this->assertIsArray($curl);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $curl);
        $this->assertContains('push.example.test:443:93.184.216.34', $curl[CURLOPT_RESOLVE]);
    }
}
