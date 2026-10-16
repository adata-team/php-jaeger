<?php

namespace Adata\LaravelJaeger;

use Adata\LaravelJaeger\Codec\HexTextCodec;
use Adata\LaravelJaeger\Reporter\ThresholdReporter;
use Jaeger\Codec\TextCodec;
use Jaeger\Config;
use OpenTracing\Formats;
use OpenTracing\GlobalTracer;
use OpenTracing\NoopTracer;
use OpenTracing\Span;
use OpenTracing\SpanContext;
use OpenTracing\Tracer;
use ReflectionClass;
use SplDoublyLinkedList;
use SplStack;
use Throwable;

/**
 * All public methods are best-effort: any exception raised by the underlying
 * tracer or reporter is swallowed so tracing outages can never break the
 * hosting application. On construction failure the class falls back to a
 * no-op tracer and every subsequent call becomes a silent no-op.
 */
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

    /** @var int */
    private $flushMinDurationMs = 0;

    /** @var int|null Microseconds since epoch when the root span opened. */
    private $rootStartMicros = null;

    /** @var ThresholdReporter|null Set only when threshold sampling is on. */
    private $thresholdReporter = null;

    /**
     * @param Config|null $config             Null → pure no-op mode.
     * @param int         $flushMinDurationMs Tail-based sampling threshold in
     *                                        milliseconds. 0 (default) = flush
     *                                        every trace. Positive = only flush
     *                                        traces whose root span lasted at
     *                                        least this long; others get
     *                                        dropped at finish() time.
     */
    public function __construct(Config $config = null, $flushMinDurationMs = 0)
    {
        $this->spans = new SplStack();
        $this->spans->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO | SplDoublyLinkedList::IT_MODE_KEEP);
        $this->flushMinDurationMs = (int) $flushMinDurationMs;

        if ($config === null) {
            $this->tracer = new NoopTracer();
            return;
        }

        try {
            $config->initializeTracer();
            $this->tracer = GlobalTracer::get();

            $this->installHexTextCodec();

            if ($this->flushMinDurationMs > 0) {
                $this->installThresholdReporter();
            }
        } catch (Throwable $e) {
            // Agent DNS lookup / config errors / etc. must not kill the app.
            $this->tracer = new NoopTracer();
        }
    }

    /**
     * Replace Tracer's TEXT_MAP / HTTP_HEADERS codecs with a hex-serializing
     * variant. jaeger-client-php v1.5.x emits trace-id as a decimal string
     * (randomId() returns `(string) random_int(...)` and TextCodec only
     * hex-formats when `is_int` is true), which produces an uber-trace-id
     * header downstream OTel/Jaeger consumers mis-parse as hex and route to
     * a different trace. See HexTextCodec for the full story.
     *
     * @return void
     */
    private function installHexTextCodec()
    {
        try {
            $ref = new ReflectionClass($this->tracer);
            if (!$ref->hasProperty('codecs')) {
                return;
            }
            $prop = $ref->getProperty('codecs');
            $prop->setAccessible(true);
            $codecs = $prop->getValue($this->tracer);
            if (!is_array($codecs)) {
                return;
            }

            foreach ([Formats\TEXT_MAP, Formats\HTTP_HEADERS] as $format) {
                if (!isset($codecs[$format])) {
                    continue;
                }
                $old = $codecs[$format];
                if (!$old instanceof TextCodec || $old instanceof HexTextCodec) {
                    continue;
                }
                $codecs[$format] = $this->rebuildAsHexCodec($old);
            }

            $prop->setValue($this->tracer, $codecs);
        } catch (Throwable $e) {
            // Reflection shape changed between client versions — leave the
            // stock codec in place rather than crash the tracer.
        }
    }

    /**
     * Mirror the private config of an existing TextCodec into a HexTextCodec.
     * The three names Tracer passes in are the only ones Config exposes; if
     * they can't be read for some reason, fall back to the Jaeger defaults.
     *
     * @param TextCodec $old
     * @return HexTextCodec
     */
    private function rebuildAsHexCodec(TextCodec $old)
    {
        $oldRef = new ReflectionClass($old);
        $read = function ($name, $default) use ($oldRef, $old) {
            if (!$oldRef->hasProperty($name)) {
                return $default;
            }
            $p = $oldRef->getProperty($name);
            $p->setAccessible(true);
            $v = $p->getValue($old);
            return $v === null ? $default : $v;
        };

        return new HexTextCodec(
            (bool) $read('urlEncoding', false),
            (string) $read('traceIdHeader', \Jaeger\TRACE_ID_HEADER),
            (string) $read('baggagePrefix', \Jaeger\BAGGAGE_HEADER_PREFIX),
            (string) $read('debugIdHeader', \Jaeger\DEBUG_ID_HEADER_KEY)
        );
    }

    /**
     * Swap the tracer's reporter with our buffering wrapper. Uses reflection
     * because jaeger-client-php's Tracer keeps the reporter private and its
     * Config factory doesn't expose a setter.
     *
     * @return void
     */
    private function installThresholdReporter()
    {
        try {
            $ref = new ReflectionClass($this->tracer);
            if (!$ref->hasProperty('reporter')) {
                return;
            }
            $prop = $ref->getProperty('reporter');
            $prop->setAccessible(true);
            $inner = $prop->getValue($this->tracer);
            if (!$inner instanceof \Jaeger\Reporter\ReporterInterface) {
                return;
            }
            $this->thresholdReporter = new ThresholdReporter($inner);
            $prop->setValue($this->tracer, $this->thresholdReporter);
        } catch (Throwable $e) {
            // Fall back to normal behavior on any reflection failure.
        }
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
        try {
            $options = ['tags' => self::normalizeTags($tags)];

            $parent = $this->getCurrentSpan();
            if ($parent !== null) {
                $options['child_of'] = $parent->getContext();
            } elseif ($this->serverContext !== null) {
                $options['child_of'] = $this->serverContext;
            }

            $span = $this->tracer->startSpan($name, $options);
            $this->spans->push($span);

            if ($this->rootStartMicros === null) {
                $this->rootStartMicros = (int) (microtime(true) * 1000000);
            }

            return $span;
        } catch (Throwable $e) {
            return null;
        }
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
        try {
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
                    foreach (self::normalizeTags($tags) as $k => $v) {
                        try { $span->setTag($k, $v); } catch (Throwable $e) {}
                    }
                    try { $span->finish(); } catch (Throwable $e) {}
                    $found = true;
                    continue;
                }

                $keep[] = $span;
            }

            // Restore surviving spans back onto the stack in original order.
            for ($i = count($keep) - 1; $i >= 0; $i--) {
                $this->spans->push($keep[$i]);
            }
        } catch (Throwable $e) {
            // swallow
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
        try {
            $endMicros = (int) (microtime(true) * 1000000);
            $startMicros = $endMicros - (int) ($durationMs * 1000);

            $options = [
                'tags' => self::normalizeTags($tags),
                'start_time' => $startMicros,
            ];

            $parent = $this->getCurrentSpan();
            if ($parent !== null) {
                $options['child_of'] = $parent->getContext();
            } elseif ($this->serverContext !== null) {
                $options['child_of'] = $this->serverContext;
            }

            $span = $this->tracer->startSpan($name, $options);
            $span->finish($endMicros);
        } catch (Throwable $e) {
            // swallow
        }
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
        if ($span === null) {
            return null;
        }
        try {
            $this->tracer->inject($span->getContext(), Formats\TEXT_MAP, $carrier);
        } catch (Throwable $e) {
            // swallow — span exists, just no propagation
        }

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
        try {
            $span = $this->getCurrentSpan();
            if ($span === null) {
                return;
            }
            $this->tracer->inject($span->getContext(), Formats\TEXT_MAP, $carrier);
        } catch (Throwable $e) {
            // swallow
        }
    }

    /**
     * Attach tags to the currently active span. No-op if the stack is empty.
     *
     * @param array $tags
     * @return void
     */
    public function addTags(array $tags)
    {
        try {
            $span = $this->getCurrentSpan();
            if ($span === null) {
                return;
            }
            foreach (self::normalizeTags($tags) as $k => $v) {
                try { $span->setTag($k, $v); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            // swallow
        }
    }

    /**
     * jonahgeorge/jaeger-client-php + ZIPKIN_OVER_COMPACT_UDP silently
     * miscodes integer and float tag values: they arrive at Jaeger as
     * base64-encoded ASCII and the parser bails out ('Cannot parse Zipkin
     * value MjAw: unexpected EOF'). String-encoding sidesteps the type
     * mismatch and renders correctly in the UI. Booleans and non-scalars
     * are passed through unchanged.
     *
     * @param array $tags
     * @return array
     */
    private static function normalizeTags(array $tags)
    {
        foreach ($tags as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $tags[$k] = (string) $v;
            }
        }
        return $tags;
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

        // Tail-based sampling: if the root span was faster than the
        // configured threshold, drop the whole trace instead of shipping
        // it. Only fires when the ThresholdReporter was successfully
        // installed in the constructor.
        if ($this->thresholdReporter !== null
            && $this->flushMinDurationMs > 0
            && $this->rootStartMicros !== null) {
            $elapsedMs = ((microtime(true) * 1000000) - $this->rootStartMicros) / 1000;
            if ($elapsedMs < $this->flushMinDurationMs) {
                $this->thresholdReporter->discard();
                return;
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
