<?php

namespace Adata\LaravelJaeger\Tests\Codec;

use Adata\LaravelJaeger\Codec\HexTextCodec;
use Jaeger\SpanContext;
use PHPUnit\Framework\TestCase;

class HexTextCodecTest extends TestCase
{
    public function testDecimalStringTraceIdIsSerializedAsHex()
    {
        $codec = new HexTextCodec();
        // 5498902247430173171 — a real id observed on dev when the stock
        // TextCodec was in use. 19 chars decimal; must become 16 chars hex.
        $ctx = new SpanContext(
            '5498902247430173171',
            '4197437543050827456',
            '4844373928751924897',
            1
        );
        $carrier = [];

        $codec->inject($ctx, $carrier);

        $this->assertArrayHasKey('uber-trace-id', $carrier);
        $parts = explode(':', $carrier['uber-trace-id']);
        $this->assertCount(4, $parts);
        $this->assertSame('4c500675a8ac31f3', $parts[0]);  // dechex(5498902247430173171)
        $this->assertSame(16, strlen($parts[0]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $parts[0]);
        $this->assertSame('1', $parts[3]);
    }

    public function testIntTraceIdIsSerializedAsHex()
    {
        $codec = new HexTextCodec();
        $ctx = new SpanContext(0x1a2b3c, 0xdeadbeef, 0, 1);
        $carrier = [];

        $codec->inject($ctx, $carrier);

        $parts = explode(':', $carrier['uber-trace-id']);
        $this->assertSame('00000000001a2b3c', $parts[0]);
        $this->assertSame('00000000deadbeef', $parts[1]);
        $this->assertSame('0000000000000000', $parts[2]);
    }

    public function testHexStringTraceIdIsLeftAsHex()
    {
        $codec = new HexTextCodec();
        // Already-hex id, e.g. an extracted upstream context being re-emitted.
        $ctx = new SpanContext('4c500675a8ac31f3', '3a3f6560e9deb8c0', '433ccfc311dde4a1', 1);
        $carrier = [];

        $codec->inject($ctx, $carrier);

        $parts = explode(':', $carrier['uber-trace-id']);
        $this->assertSame('4c500675a8ac31f3', $parts[0]);
        $this->assertSame('3a3f6560e9deb8c0', $parts[1]);
        $this->assertSame('433ccfc311dde4a1', $parts[2]);
    }

    public function testBaggageIsForwarded()
    {
        $codec = new HexTextCodec();
        $ctx = new SpanContext('42', '7', null, 1, ['user' => 'alice', 'tier' => 'gold']);
        $carrier = [];

        $codec->inject($ctx, $carrier);

        $this->assertSame('alice', $carrier['uberctx-user']);
        $this->assertSame('gold', $carrier['uberctx-tier']);
    }

    public function testBaggageIsUrlEncodedWhenConfigured()
    {
        $codec = new HexTextCodec(true);
        $ctx = new SpanContext('42', '7', null, 1, ['note' => 'hello world']);
        $carrier = [];

        $codec->inject($ctx, $carrier);

        $this->assertSame('hello+world', $carrier['uberctx-note']);
    }

    public function testExtractRoundTripsADecimalHeader()
    {
        $codec = new HexTextCodec();
        // Simulates an inbound request from a stock-TextCodec PHP peer still
        // sending decimal: the extracted SpanContext holds the raw string, so
        // our subsequent inject() will hex-normalize it on the way out.
        $extracted = $codec->extract(['uber-trace-id' => '5498902247430173171:3a3f6560e9deb8c0:0:1']);
        $this->assertNotNull($extracted);

        $carrier = [];
        $codec->inject($extracted, $carrier);
        $this->assertSame('4c500675a8ac31f3', explode(':', $carrier['uber-trace-id'])[0]);
    }
}