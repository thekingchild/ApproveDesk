<?php

namespace Tests\Unit\Support;

use App\Support\CloudflareRocketLoader;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CloudflareRocketLoaderTest extends TestCase
{
    public function test_it_places_cfasync_before_src_on_external_scripts(): void
    {
        $response = new Response(
            '<script type="module" src="/build/assets/app-abc.js" data-navigate-track="reload"></script>'
            .'<script src="/livewire/livewire.js" data-csrf="token"></script>'
            .'<script src="/flux/flux.js" data-navigate-once></script>'
            .'<script>window.Flux = {}</script>',
            200,
            ['Content-Type' => 'text/html'],
        );

        CloudflareRocketLoader::protect($response);

        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<script data-cfasync="false" type="module" src="\/build\/assets\/app-abc\.js"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<script data-cfasync="false" src="\/livewire\/livewire\.js"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<script data-cfasync="false" src="\/flux\/flux\.js"/',
            $html,
        );
        $this->assertStringContainsString('<script>window.Flux = {}</script>', $html);
    }
}
