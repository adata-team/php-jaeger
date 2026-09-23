<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\Jaeger;

class PackageBootTest extends TestCase
{
    public function testConfigIsAvailable()
    {
        $this->assertSame('adata-jaeger-tests', config('jaeger.name'));
        $this->assertSame('127.0.0.1', config('jaeger.local_agent.reporting_host'));
    }

    public function testJaegerIsBoundAsSingleton()
    {
        $a = $this->app->make(Jaeger::class);
        $b = $this->app->make(Jaeger::class);

        $this->assertInstanceOf(Jaeger::class, $a);
        $this->assertSame($a, $b);
    }

    public function testTerminatingCallbackFlushesJaeger()
    {
        /** @var Jaeger $jaeger */
        $jaeger = $this->app->make(Jaeger::class);
        $jaeger->start('boot.terminating');
        $this->assertNotNull($jaeger->getCurrentSpan());

        // Simulate the framework shutting the request down.
        $this->app->terminate();

        $this->assertNull($jaeger->getCurrentSpan());
    }
}