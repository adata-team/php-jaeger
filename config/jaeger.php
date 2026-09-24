<?php

use Jaeger\Config;

return [
    /*
    |--------------------------------------------------------------------------
    | Service name
    |--------------------------------------------------------------------------
    | Falls back to APP_NAME. Shown as the service in Jaeger UI.
    */
    'name' => env('JAEGER_SERVICE_NAME', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Sampler
    |--------------------------------------------------------------------------
    | JAEGER_SAMPLE_RATE accepts values from 0 to 1. 0.1 samples 10% of
    | traces, 1 samples every trace.
    */
    'sampler' => [
        'type'  => \Jaeger\SAMPLER_TYPE_PROBABILISTIC,
        'param' => (float) env('JAEGER_SAMPLE_RATE', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local agent
    |--------------------------------------------------------------------------
    */
    'local_agent' => [
        'reporting_host' => env('JAEGER_AGENT_HOST', 'jaeger'),
        'reporting_port' => (int) env('JAEGER_AGENT_PORT', 5775),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatch mode
    |--------------------------------------------------------------------------
    | Available: JAEGER_OVER_BINARY_UDP, JAEGER_OVER_BINARY_HTTP,
    | ZIPKIN_OVER_COMPACT_UDP (default).
    */
    'dispatch_mode' => Config::ZIPKIN_OVER_COMPACT_UDP,

    /*
    |--------------------------------------------------------------------------
    | Excluded paths
    |--------------------------------------------------------------------------
    | Request paths that JaegerMiddleware should skip (no span created, no
    | trace context extracted). Patterns are matched via Request::is(), so
    | wildcards work: 'health', 'metrics/*', 'api/v1/ping'. Supply as an
    | array or a comma-separated JAEGER_EXCLUDE_PATHS env value.
    */
    'exclude_paths' => array_values(array_filter(array_map('trim', explode(
        ',',
        env('JAEGER_EXCLUDE_PATHS', '')
    )))),

    /*
    |--------------------------------------------------------------------------
    | Listeners
    |--------------------------------------------------------------------------
    | All listeners are disabled by default. Enable via env or override
    | the handler class with a project-specific implementation.
    */
    'listeners' => [
        'http' => [
            'enabled' => (bool) env('JAEGER_HTTP_LISTENER_ENABLED', false),
            'handler' => \Adata\LaravelJaeger\JaegerMiddleware::class,
        ],
        'console' => [
            'enabled' => (bool) env('JAEGER_CONSOLE_LISTENER_ENABLED', false),
            'handler' => \Adata\LaravelJaeger\Listeners\CommandListener::class,
        ],
        'query' => [
            'enabled' => (bool) env('JAEGER_QUERY_LISTENER_ENABLED', false),
            'handler' => \Adata\LaravelJaeger\Listeners\QueryListener::class,
        ],
        'job' => [
            'enabled' => (bool) env('JAEGER_JOB_LISTENER_ENABLED', false),
            'handler' => \Adata\LaravelJaeger\Listeners\JobListener::class,
        ],
    ],
];
