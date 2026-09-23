<?php

namespace Adata\LaravelJaeger;

use Jaeger\Config;
use OpenTracing\Formats;
use OpenTracing\GlobalTracer;
use OpenTracing\Reference;
use OpenTracing\Span;
use OpenTracing\SpanContext;
use OpenTracing\Tracer;
use SplDoublyLinkedList;
use SplStack;
use Throwable;

class Jaeger
{
    /** @var Tracer */
    private $tracer;

    /** @var SplStack */
    private $spans;

    /** @var SpanContext|null */
    private $serverContext;

    /** @var bool */
    private $isFinished = false;

    public function __construct(Config $config)
    {
        $config->initializeTracer();
        $this->tracer = GlobalTracer::get();

        $this->spans = new SplStack();
        $this->spans->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO | SplDoublyLinkedList::IT_MODE_KEEP);
    }

    /**
     * Start a new span. If there is already an active span it becomes the
     * parent; otherwise a server context extracted via initServerContext()
     * is used as parent.
     *
     * @param string $name
     * @param array  $tags
     * @return Span
     */
    public function start($name, array $tags = [])
    {
        $options = ['tags' => $tags];

        $parent = $this->getCurrentSpan();
        if ($parent !== null) {
            $options['references'] = [
                Reference::create(Reference::CHILD_OF, $parent->getContext()),
            ];
        } elseif ($this->serverContext !== null) {
            $options['references'] = [
                Reference::create(Reference::CHILD_OF, $this->serverContext),
            ];
        }

        $span = $this->tracer->startSpan($name, $options);
        $this->spans->push($span);

        return $span;
    }

    /**
     * Stop the most recent span with the given operation name and attach
     * additional tags. Silently ignores unknown names.
     *
     * @param string $name
     * @param array  $tags
     * @return void
     */
    public function stop($name, array $tags = [])
    {
        if ($this->spans->isEmpty()) {
            return;
        }

        $keep = [];
        $found = false;

        // Pop LIFO, drop the first match, keep the rest in original order.
        while (!$this->spans->isEmpty()) {
            /** @var Span $span */
            $span = $this->spans->pop();

            if (!$found && $span->getOperationName() === $name) {
                foreach ($tags as $k => $v) {
                    $span->setTag($k, $v);
                }
                $span->finish();
                $found = true;
                continue;
            }

            $keep[] = $span;
        }

        // Restore surviving spans back onto the stack in original order.
        for ($i = count($keep) - 1; $i >= 0; $i--) {
            $this->spans->push($keep[$i]);
        }
    }

    /**
     * Create and immediately finish a span with an explicit duration in
     * microseconds. Useful for reporting operations that were timed
     * externally (e.g. DB queries reported by a listener after the fact).
     *
     * @param string $name
     * @param float  $durationMs Duration in milliseconds.
     * @param array  $tags
     * @return void
     */
    public function startStop($name, $durationMs, array $tags = [])
    {
        $endMicros = (int) (microtime(true) * 1000000);
        $startMicros = $endMicros - (int) ($durationMs * 1000);

        $options = [
            'tags' => $tags,
            'start_time' => $startMicros,
        ];

        $parent = $this->getCurrentSpan();
        if ($parent !== null) {
            $options['references'] = [
                Reference::create(Reference::CHILD_OF, $parent->getContext()),
            ];
        } elseif ($this->serverContext !== null) {
            $options['references'] = [
                Reference::create(Reference::CHILD_OF, $this->serverContext),
            ];
        }

        $span = $this->tracer->startSpan($name, $options);
        $span->finish($endMicros);
    }

    /**
     * Start a span and inject its context into the given carrier so it can
     * be forwarded to a downstream service (HTTP headers, message payload,
     * etc.).
     *
     * @param string $name
     * @param array  $tags
     * @param array  $carrier By reference.
     * @return Span
     */
    public function startWithInject($name, array $tags, array &$carrier)
    {
        $span = $this->start($name, $tags);
        $this->tracer->inject($span->getContext(), Formats\TEXT_MAP, $carrier);

        return $span;
    }

    /**
     * Inject the currently active span's context into the given carrier.
     *
     * @param array $carrier By reference.
     * @return void
     */
    public function inject(array &$carrier)
    {
        $span = $this->getCurrentSpan();
        if ($span === null) {
            return;
        }
        $this->tracer->inject($span->getContext(), Formats\TEXT_MAP, $carrier);
    }

    /**
     * @return Span|null
     */
    public function getCurrentSpan()
    {
        if ($this->spans->isEmpty()) {
            return null;
        }

        return $this->spans->top();
    }

    /**
     * Extract a parent trace context from an incoming request (typically
     * $_SERVER-style array).
     *
     * @param array $server
     * @return void
     */
    public function initServerContext(array $server)
    {
        $carrier = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $carrier[$header] = $value;
            }
        }

        try {
            $context = $this->tracer->extract(Formats\TEXT_MAP, $carrier);
            if ($context !== null) {
                $this->serverContext = $context;
            }
        } catch (Throwable $e) {
            // Malformed upstream context — start a fresh trace.
        }
    }

    /**
     * Finish every open span and flush the tracer. Safe to call multiple
     * times.
     *
     * @return void
     */
    public function finish()
    {
        if ($this->isFinished) {
            return;
        }
        $this->isFinished = true;

        while (!$this->spans->isEmpty()) {
            /** @var Span $span */
            $span = $this->spans->pop();
            try {
                $span->finish();
            } catch (Throwable $e) {
                // ignore
            }
        }

        try {
            $this->tracer->flush();
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * ID of the currently active span, if any.
     *
     * @return string|null
     */
    public function getTraceId()
    {
        $span = $this->getCurrentSpan();
        if ($span === null) {
            return null;
        }

        $context = $span->getContext();
        if (method_exists($context, 'getTraceId')) {
            return (string) $context->getTraceId();
        }

        return null;
    }

    /**
     * ID of the outermost (root) span in the current stack.
     *
     * @return string|null
     */
    public function getRootTraceId()
    {
        if ($this->spans->isEmpty()) {
            return null;
        }

        // Bottom of the stack is the first pushed span.
        $root = $this->spans->bottom();
        $context = $root->getContext();
        if (method_exists($context, 'getTraceId')) {
            return (string) $context->getTraceId();
        }

        return null;
    }

    public function __destruct()
    {
        $this->finish();
    }
}
