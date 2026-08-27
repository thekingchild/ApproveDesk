<?php

namespace Tests;

use App\Support\DnsResolver;
use App\Support\OutboundDestination;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\ArrayDnsResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $resolver = new ArrayDnsResolver;
        $this->app->instance(DnsResolver::class, $resolver);
        $this->app->instance(OutboundDestination::class, new OutboundDestination($resolver));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
