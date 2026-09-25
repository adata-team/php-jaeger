<?php

namespace Adata\LaravelJaeger\Guzzle;

use Adata\LaravelJaeger\Jaeger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle handler-stack middleware that opens a client-kind span for every
 * outgoing request, injects the trace context into the request headers and
 * closes the span with the response status (or the error).
 *
 * Usage:
 *
 *   use GuzzleHttp\Client;
 *   use GuzzleHttp\HandlerStack;
 *   use Adata\LaravelJaeger\Guzzle\JaegerGuzzleMiddleware;
 *
 *   $stack = HandlerStack::create();
 *   $stack->push(new JaegerGuzzleMiddleware(app(Jaeger::class)), 'jaeger');
 *   $client = new Client(['handler' => $stack]);
 *
 * Bind it into every Guzzle client the app resolves (e.g. in a
 * ServiceProvider that binds Client::class) so every downstream call is
 * automatically part of the same trace as the incoming request.
 *
 * All tracing side-effects are best-effort: any exception raised by the
 * tracer is swallowed so the outgoing HTTP call never breaks because of
 * observability.
 */
class JaegerGuzzleMiddleware
{
    /** @var Jaeger */
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    /**
     * Append Guzzle transfer-stat timings (DNS, connect, TTFB, total) as
     * span tags. Values come from libcurl in seconds; we surface them in
     * milliseconds as strings to sidestep the Zipkin numeric-tag bug.
     *
     * @param array $tags By reference — receives new *_ms keys.
     * @param mixed $stats Guzzle TransferStats or null when not available.
     * @return void
     */
    private static function mergeTransferTags(array &$tags, $stats)
    {
        if ($stats === null || !method_exists($stats, 'getHandlerStats')) {
            return;
        }
        $handlerStats = $stats->getHandlerStats();
        if (!is_array($handlerStats)) {
            return;
        }
        $keys = [
            'total_time'         => 'http.total_time_ms',
            'namelookup_time'    => 'http.dns_time_ms',
            'connect_time'       => 'http.connect_time_ms',
            'appconnect_time'    => 'http.tls_time_ms',
            'pretransfer_time'   => 'http.pretransfer_time_ms',
            'starttransfer_time' => 'http.ttfb_ms',
        ];
        foreach ($keys as $curlKey => $tagName) {
            if (isset($handlerStats[$curlKey]) && $handlerStats[$curlKey] > 0) {
                $tags[$tagName] = (string) round($handlerStats[$curlKey] * 1000, 2);
            }
        }
    }

    /**
     * @param callable $handler
     * @return callable
     */
    public function __invoke(callable $handler)
    {
        $jaeger = $this->jaeger;

        return function (RequestInterface $request, array $options) use ($handler, $jaeger) {
            $operation = null;
            try {
                $uri = $request->getUri();
                $operation = 'HTTP ' . $request->getMethod() . ' ' . $uri->getHost();

                $jaeger->start($operation, [
                    'http.method' => $request->getMethod(),
                    'http.url'    => (string) $uri,
                    'http.host'   => $uri->getHost(),
                    'span.kind'   => 'client',
                ]);

                $headers = [];
                $jaeger->inject($headers);
                foreach ($headers as $k => $v) {
                    $request = $request->withHeader($k, $v);
                }
            } catch (Throwable $e) {
                // never break the outgoing call because of tracing setup
            }

            // Capture Guzzle transfer stats so we can break the total
            // request duration into DNS / connect / TTFB / transfer phases.
            $transferStats = null;
            $originalOnStats = isset($options['on_stats']) ? $options['on_stats'] : null;
            $options['on_stats'] = function ($stats) use (&$transferStats, $originalOnStats) {
                $transferStats = $stats;
                if (is_callable($originalOnStats)) {
                    $originalOnStats($stats);
                }
            };

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use ($operation, $jaeger, &$transferStats) {
                    try {
                        if ($operation !== null) {
                            // String-cast avoids Zipkin-compact-UDP integer
                            // serialization mismatch ('MjAw' parse errors).
                            $tags = ['http.status_code' => (string) $response->getStatusCode()];
                            self::mergeTransferTags($tags, $transferStats);
                            $jaeger->stop($operation, $tags);
                        }
                    } catch (Throwable $e) {
                        // swallow
                    }
                    return $response;
                },
                function ($reason) use ($operation, $jaeger, &$transferStats) {
                    try {
                        if ($operation !== null) {
                            $msg = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
                            $cls = $reason instanceof Throwable ? get_class($reason) : 'error';
                            $tags = [
                                'error'         => true,
                                'error.message' => $msg,
                                'error.class'   => $cls,
                            ];
                            self::mergeTransferTags($tags, $transferStats);
                            $jaeger->stop($operation, $tags);
                        }
                    } catch (Throwable $e) {
                        // swallow
                    }
                    // Re-throw as-is so the caller sees the original failure.
                    if ($reason instanceof Throwable) {
                        throw $reason;
                    }
                    throw new \RuntimeException((string) $reason);
                }
            );
        };
    }
}