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

            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use ($operation, $jaeger) {
                    try {
                        if ($operation !== null) {
                            // String-cast avoids Zipkin-compact-UDP integer
                            // serialization mismatch ('MjAw' parse errors).
                            $jaeger->stop($operation, [
                                'http.status_code' => (string) $response->getStatusCode(),
                            ]);
                        }
                    } catch (Throwable $e) {
                        // swallow
                    }
                    return $response;
                },
                function ($reason) use ($operation, $jaeger) {
                    try {
                        if ($operation !== null) {
                            $msg = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
                            $cls = $reason instanceof Throwable ? get_class($reason) : 'error';
                            $jaeger->stop($operation, [
                                'error'         => true,
                                'error.message' => $msg,
                                'error.class'   => $cls,
                            ]);
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