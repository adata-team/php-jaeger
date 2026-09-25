<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\Guzzle\JaegerGuzzleMiddleware;
use Adata\LaravelJaeger\Jaeger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class JaegerGuzzleMiddlewareTest extends TestCase
{
    /** @var Jaeger */
    private $jaeger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jaeger = $this->app->make(Jaeger::class);
    }

    /**
     * @param MockHandler $mock
     * @return Client
     */
    private function clientWith(MockHandler $mock)
    {
        $stack = HandlerStack::create($mock);
        $stack->push(new JaegerGuzzleMiddleware($this->jaeger), 'jaeger');
        return new Client(['handler' => $stack]);
    }

    public function testInjectsTraceHeadersIntoOutgoingRequest()
    {
        // Open a parent span so there is a context to propagate.
        $this->jaeger->start('parent.op');

        $mock = new MockHandler([new Response(200, [], 'ok')]);
        $captured = [];
        $stack = HandlerStack::create($mock);
        $stack->push(new JaegerGuzzleMiddleware($this->jaeger), 'jaeger');
        $stack->push(function (callable $next) use (&$captured) {
            return function ($request, $options) use ($next, &$captured) {
                $captured = $request->getHeaders();
                return $next($request, $options);
            };
        }, 'capture');

        $client = new Client(['handler' => $stack]);
        $client->request('GET', 'http://downstream.local/foo');

        // The client-php tracer injects at least one of the standard keys.
        $hasTraceHeader = false;
        foreach ($captured as $name => $_) {
            $lower = strtolower($name);
            if (strpos($lower, 'uber-trace-id') === 0
                || strpos($lower, 'trace-id') !== false
                || strpos($lower, 'traceparent') === 0) {
                $hasTraceHeader = true;
                break;
            }
        }
        $this->assertTrue($hasTraceHeader, 'expected a trace header on the outgoing request');

        $this->jaeger->stop('parent.op');
    }

    public function testClientSpanIsOpenedAndClosedAroundRequest()
    {
        $mock = new MockHandler([new Response(200)]);
        $client = $this->clientWith($mock);

        $this->assertNull($this->jaeger->getCurrentSpan());
        $client->request('GET', 'http://downstream.local/status');
        $this->assertNull(
            $this->jaeger->getCurrentSpan(),
            'the client span must be closed after the response'
        );
    }

    public function testHttpErrorPropagatesAndClosesSpan()
    {
        $mock = new MockHandler([
            new ConnectException('boom', new Request('GET', 'http://x')),
        ]);
        $client = $this->clientWith($mock);

        try {
            $client->request('GET', 'http://x');
            $this->fail('expected ConnectException to propagate');
        } catch (ConnectException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Stack must be back to empty — the error branch closed the span.
        $this->assertNull($this->jaeger->getCurrentSpan());
    }
}
