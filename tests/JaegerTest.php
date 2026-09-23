<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\Jaeger;

class JaegerTest extends TestCase
{
    /** @var Jaeger */
    private $jaeger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jaeger = $this->app->make(Jaeger::class);
    }

    public function testStartPushesSpanAndStopPops()
    {
        $this->assertNull($this->jaeger->getCurrentSpan());

        $this->jaeger->start('op.a', ['tag' => 1]);
        $this->assertNotNull($this->jaeger->getCurrentSpan());
        $this->assertSame('op.a', $this->jaeger->getCurrentSpan()->getOperationName());

        $this->jaeger->stop('op.a', ['result' => 'ok']);
        $this->assertNull($this->jaeger->getCurrentSpan());
    }

    public function testNestedSpansUnwindInLifoOrder()
    {
        $this->jaeger->start('outer');
        $this->jaeger->start('inner');

        $this->assertSame('inner', $this->jaeger->getCurrentSpan()->getOperationName());

        $this->jaeger->stop('inner');
        $this->assertSame('outer', $this->jaeger->getCurrentSpan()->getOperationName());

        $this->jaeger->stop('outer');
        $this->assertNull($this->jaeger->getCurrentSpan());
    }

    public function testStopMatchesInnermostOccurrenceOnly()
    {
        $this->jaeger->start('same');
        $this->jaeger->start('same');
        $this->jaeger->stop('same');

        // One instance should still be on the stack.
        $this->assertNotNull($this->jaeger->getCurrentSpan());
        $this->assertSame('same', $this->jaeger->getCurrentSpan()->getOperationName());

        $this->jaeger->stop('same');
        $this->assertNull($this->jaeger->getCurrentSpan());
    }

    public function testStopWithUnknownNameIsNoop()
    {
        $this->jaeger->start('present');
        $this->jaeger->stop('missing');

        $this->assertSame('present', $this->jaeger->getCurrentSpan()->getOperationName());
    }

    public function testStartStopEmitsSpanWithoutTouchingStack()
    {
        $this->jaeger->start('outer');
        $this->jaeger->startStop('db.select', 1.5, ['db.statement' => 'SELECT 1']);

        $this->assertSame('outer', $this->jaeger->getCurrentSpan()->getOperationName());
    }

    public function testStartWithInjectPopulatesCarrier()
    {
        $carrier = [];
        $this->jaeger->startWithInject('rpc.call', ['peer' => 'billing'], $carrier);

        $this->assertNotEmpty($carrier, 'carrier should contain trace context headers');

        $this->jaeger->stop('rpc.call');
    }

    public function testInjectOnEmptyStackIsSafe()
    {
        $carrier = ['x-existing' => 'keep'];
        $this->jaeger->inject($carrier);
        $this->assertSame(['x-existing' => 'keep'], $carrier);
    }

    public function testFinishIsIdempotent()
    {
        $this->jaeger->start('op');
        $this->jaeger->finish();
        $this->jaeger->finish(); // must not throw

        $this->assertNull($this->jaeger->getCurrentSpan());
    }

    public function testGetTraceIdReturnsStringWhenSpanActive()
    {
        $this->assertNull($this->jaeger->getTraceId());

        $this->jaeger->start('op');
        $traceId = $this->jaeger->getTraceId();
        $rootId = $this->jaeger->getRootTraceId();

        $this->assertIsString($traceId);
        $this->assertNotSame('', $traceId);
        $this->assertSame($traceId, $rootId);
    }
}
