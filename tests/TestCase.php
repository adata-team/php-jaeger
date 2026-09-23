<?php

namespace Adata\LaravelJaeger\Tests;

use Adata\LaravelJaeger\JaegerMiddleware;
use Adata\LaravelJaeger\LaravelJaegerServiceProvider;
use Adata\LaravelJaeger\Listeners\CommandListener;
use Adata\LaravelJaeger\Listeners\JobListener;
use Adata\LaravelJaeger\Listeners\QueryListener;
use Jaeger\Config;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [LaravelJaegerServiceProvider::class];
    }

    /**
     * Fully replace the jaeger config so the shallow mergeConfigFrom doesn't
     * bite us and every test hits a local UDP endpoint (no DNS lookup for
     * 'jaeger', no real reporting required).
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('jaeger', [
            'name'    => 'adata-jaeger-tests',
            'sampler' => [
                'type'  => \Jaeger\SAMPLER_TYPE_CONST,
                'param' => true,
            ],
            'local_agent' => [
                'reporting_host' => '127.0.0.1',
                'reporting_port' => 5775,
            ],
            'dispatch_mode' => Config::ZIPKIN_OVER_COMPACT_UDP,
            'listeners' => [
                'http' => [
                    'enabled' => $this->httpListenerEnabled(),
                    'handler' => JaegerMiddleware::class,
                ],
                'console' => [
                    'enabled' => false,
                    'handler' => CommandListener::class,
                ],
                'query' => [
                    'enabled' => false,
                    'handler' => QueryListener::class,
                ],
                'job' => [
                    'enabled' => false,
                    'handler' => JobListener::class,
                ],
            ],
        ]);
    }

    /**
     * Override in a test to flip the http listener on.
     *
     * @return bool
     */
    protected function httpListenerEnabled()
    {
        return false;
    }
}