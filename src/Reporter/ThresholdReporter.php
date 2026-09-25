<?php

namespace Adata\LaravelJaeger\Reporter;

use Jaeger\Reporter\ReporterInterface;
use Jaeger\Span;

/**
 * Wraps an inner reporter and buffers every reported span in memory instead
 * of forwarding it immediately. Jaeger::finish() then calls one of:
 *
 *   flushBuffered() — forward the whole buffer to the inner reporter and
 *                     close it (spans reach the agent).
 *   discard()       — drop the buffer (spans are gone).
 *
 * Used to implement tail-based sampling: at the end of a request the app
 * knows the root-span duration and can decide whether the trace is worth
 * shipping to Jaeger. Fast, boring requests are dropped; slow requests are
 * kept in full for debugging.
 */
class ThresholdReporter implements ReporterInterface
{
    /** @var ReporterInterface */
    private $inner;

    /** @var Span[] */
    private $buffer = [];

    public function __construct(ReporterInterface $inner)
    {
        $this->inner = $inner;
    }

    /**
     * {@inheritdoc}
     */
    public function reportSpan(Span $span)
    {
        $this->buffer[] = $span;
    }

    /**
     * {@inheritdoc}
     *
     * Bypass semantics: if the tracer calls close() directly (via flush())
     * we treat it as an unconditional "flush what we have", so the tracer's
     * own flush contract still works when the app didn't opt into
     * threshold-based decisions.
     */
    public function close()
    {
        $this->flushBuffered();
    }

    /**
     * Forward all buffered spans to the inner reporter and close it.
     *
     * @return void
     */
    public function flushBuffered()
    {
        foreach ($this->buffer as $span) {
            $this->inner->reportSpan($span);
        }
        $this->buffer = [];
        $this->inner->close();
    }

    /**
     * Drop all buffered spans without forwarding them.
     *
     * @return void
     */
    public function discard()
    {
        $this->buffer = [];
    }

    /**
     * @return int
     */
    public function bufferSize()
    {
        return count($this->buffer);
    }
}