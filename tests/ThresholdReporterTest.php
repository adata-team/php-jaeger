<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\Reporter\ThresholdReporter;
use Jaeger\Reporter\ReporterInterface;
use Jaeger\Span;
use PHPUnit\Framework\TestCase;

class ThresholdReporterTest extends TestCase
{
    public function testBuffersSpansAndForwardsOnFlush()
    {
        $inner = $this->createMock(ReporterInterface::class);
        $span = $this->createMock(Span::class);

        $inner->expects($this->once())->method('reportSpan')->with($span);
        $inner->expects($this->once())->method('close');

        $r = new ThresholdReporter($inner);
        $r->reportSpan($span);
        $this->assertSame(1, $r->bufferSize());
        $r->flushBuffered();
        $this->assertSame(0, $r->bufferSize());
    }

    public function testDiscardDropsBufferAndDoesNotTouchInner()
    {
        $inner = $this->createMock(ReporterInterface::class);
        $inner->expects($this->never())->method('reportSpan');
        $inner->expects($this->never())->method('close');

        $r = new ThresholdReporter($inner);
        $r->reportSpan($this->createMock(Span::class));
        $r->reportSpan($this->createMock(Span::class));
        $this->assertSame(2, $r->bufferSize());

        $r->discard();
        $this->assertSame(0, $r->bufferSize());
    }

    public function testCloseFallsBackToFlushBuffered()
    {
        $inner = $this->createMock(ReporterInterface::class);
        $span = $this->createMock(Span::class);
        $inner->expects($this->once())->method('reportSpan')->with($span);
        $inner->expects($this->once())->method('close');

        $r = new ThresholdReporter($inner);
        $r->reportSpan($span);
        $r->close();
    }
}