<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\Jaeger;
use Adata\LaravelJaeger\JaegerMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class JaegerMiddlewareTest extends TestCase
{
    public function testHandleWrapsRequestInSpanAndReturnsResponse()
    {
        /** @var Jaeger $jaeger */
        $jaeger = $this->app->make(Jaeger::class);
        $middleware = new JaegerMiddleware($jaeger);

        $request = Request::create('/orders/42', 'GET');
        $expected = new Response('ok', 200);

        $response = $middleware->handle($request, function () use ($expected) {
            return $expected;
        });

        $this->assertSame($expected, $response);
        $this->assertNull($jaeger->getCurrentSpan(), 'span must be popped after successful request');
    }

    public function testHandleStopsSpanAndRethrowsOnException()
    {
        /** @var Jaeger $jaeger */
        $jaeger = $this->app->make(Jaeger::class);
        $middleware = new JaegerMiddleware($jaeger);

        $request = Request::create('/boom', 'POST');

        $threw = false;
        try {
            $middleware->handle($request, function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($threw, 'exception should be rethrown');
        $this->assertNull($jaeger->getCurrentSpan(), 'span must be popped even on exception');
    }
}
