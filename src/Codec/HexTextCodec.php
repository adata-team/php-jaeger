<?php

namespace Adata\LaravelJaeger\Codec;

use Jaeger\Codec\TextCodec;
use OpenTracing\SpanContext;

use const Jaeger\BAGGAGE_HEADER_PREFIX;
use const Jaeger\DEBUG_ID_HEADER_KEY;
use const Jaeger\TRACE_ID_HEADER;

/**
 * Drop-in replacement for Jaeger\Codec\TextCodec whose inject() always writes
 * the trace-id component of uber-trace-id as 16-char lowercase hex.
 *
 * jonahgeorge/jaeger-client-php v1.5.x generates trace-ids via
 *     (string) random_int(0, PHP_INT_MAX)
 * so the id arrives at the codec as a decimal string. TextCodec's own
 * spanContextToString() only hex-formats the id when it's still a PHP int
 * (`is_int` branch at TextCodec.php:146), so the decimal string flows straight
 * into the header. Downstream OTel / Jaeger propagators then read the first
 * component as hex per spec, land on a different trace-id, and PHP+Go spans
 * end up in separate traces instead of one.
 *
 * extract() is inherited unchanged — the receiver side has no formatting
 * ambiguity (the parsed string just rides along in SpanContext).
 */
class HexTextCodec extends TextCodec
{
    /** @var bool */
    private $urlEncoding;

    /** @var string */
    private $traceIdHeader;

    /** @var string */
    private $baggagePrefix;

    public function __construct(
        bool $urlEncoding = false,
        string $traceIdHeader = TRACE_ID_HEADER,
        string $baggageHeaderPrefix = BAGGAGE_HEADER_PREFIX,
        string $debugIdHeader = DEBUG_ID_HEADER_KEY
    ) {
        parent::__construct($urlEncoding, $traceIdHeader, $baggageHeaderPrefix, $debugIdHeader);
        $this->urlEncoding = $urlEncoding;
        $this->traceIdHeader = str_replace('_', '-', strtolower($traceIdHeader));
        $this->baggagePrefix = str_replace('_', '-', strtolower($baggageHeaderPrefix));
    }

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContext $spanContext, &$carrier)
    {
        $carrier[$this->traceIdHeader] = self::spanContextToString(
            $spanContext->getTraceId(),
            $spanContext->getSpanId(),
            $spanContext->getParentId(),
            $spanContext->getFlags()
        );

        $baggage = $spanContext->getBaggage();
        if (empty($baggage)) {
            return;
        }

        foreach ($baggage as $key => $value) {
            $encodedValue = $this->urlEncoding ? urlencode($value) : $value;
            $carrier[$this->baggagePrefix . $key] = $encodedValue;
        }
    }

    /**
     * @param int|string|null $traceId
     * @param int|string|null $spanId
     * @param int|string|null $parentId
     * @param int             $flags
     * @return string
     */
    private static function spanContextToString($traceId, $spanId, $parentId, $flags)
    {
        $parentId = $parentId ?? 0;
        return sprintf(
            '%s:%s:%s:%x',
            self::toHex64($traceId),
            self::toHex64($spanId),
            self::toHex64($parentId),
            $flags
        );
    }

    /**
     * Normalize a jaeger-client-php id (PHP int, decimal-string from
     * (string) random_int(), or already-hex when extracted from upstream)
     * to 16-char lowercase hex.
     *
     * @param int|string|null $id
     * @return string
     */
    private static function toHex64($id)
    {
        if ($id === null || $id === '') {
            return '0000000000000000';
        }
        if (is_int($id)) {
            return sprintf('%016x', $id);
        }
        $s = (string) $id;
        // Decimal-only — the bug case. random_int() produces values ≤ PHP_INT_MAX,
        // so (int) cast is lossless on 64-bit PHP; on 32-bit PHP the client only
        // emits 31-bit ids, which also fit.
        if (ctype_digit($s)) {
            return sprintf('%016x', (int) $s);
        }
        // Already hex (upstream-propagated context, or 128-bit id).
        return str_pad(strtolower($s), 16, '0', STR_PAD_LEFT);
    }
}