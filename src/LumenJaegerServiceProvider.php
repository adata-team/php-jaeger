<?php

namespace Adata\LaravelJaeger;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Jaeger\Config;

/**
 * Lumen-specific provider. Registered manually in bootstrap/app.php:
 *
 *   $app->configure('jaeger');
 *   $app->register(\Adata\LaravelJaeger\LumenJaegerServiceProvider::class);
 *
 * If the HTTP listener is enabled you must also add the middleware yourself:
 *
 *   $app->middleware([\Adata\LaravelJaeger\JaegerMiddleware::class]);
 */
class LumenJaegerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register()
    {
        // Lumen: make sure the config file is loaded even if the user forgot
        // to call $app->configure('jaeger'). configure() is idempotent.
        if (method_exists($this->app, 'configure')) {
            $this->app->configure('jaeger');
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/jaeger.php', 'jaeger');

        $this->app->singleton(Jaeger::class, function ($app) {
            try {
                $cfg = $app['config']->get('jaeger');

                $options = [
                    'sampler'       => $cfg['sampler'],
                    'local_agent'   => $cfg['local_agent'],
                    'dispatch_mode' => $cfg['dispatch_mode'],
                ];
                if (!empty($cfg['tags'])) {
                    $options['tags'] = $cfg['tags'];
                }

                $config = new Config($options, $cfg['name']);

                return new Jaeger($config, (int) ($cfg['flush_min_duration_ms'] ?? 0));
            } catch (\Throwable $e) {
                // Config building failed — return a no-op tracer so callers
                // that inject Jaeger still work.
                return new Jaeger(null);
            }
        });
    }

    /**
     * @return void
     */
    public function boot()
    {
        $listeners = (array) $this->app['config']->get('jaeger.listeners', []);

        $this->bootConsole($listeners);
        $this->bootQuery($listeners);
        $this->bootJob($listeners);

        // Laravel's Application has terminating(); Lumen's does not. When
        // running under Lumen the JaegerMiddleware flushes the tracer after
        // the response, and Jaeger::__destruct() is a final safety net.
        if (method_exists($this->app, 'terminating')) {
            $this->app->terminating(function () {
                if ($this->app->resolved(Jaeger::class)) {
                    $this->app->make(Jaeger::class)->finish();
                }
            });
        }
    }

    /**
     * @param array $listeners
     * @return void
     */
    private function bootConsole(array $listeners)
    {
        if (empty($listeners['console']['enabled'])) {
            return;
        }
        if (!$this->app->runningInConsole()) {
            return;
        }

        $handler = $listeners['console']['handler'];
        $dispatcher = $this->app['events'];

        $dispatcher->listen(CommandStarting::class, $handler . '@starting');
        $dispatcher->listen(CommandFinished::class, $handler . '@finished');
    }

    /**
     * @param array $listeners
     * @return void
     */
    private function bootQuery(array $listeners)
    {
        if (empty($listeners['query']['enabled'])) {
            return;
        }

        $handler = $listeners['query']['handler'];
        $this->app['events']->listen(QueryExecuted::class, $handler . '@handle');
    }

    /**
     * @param array $listeners
     * @return void
     */
    private function bootJob(array $listeners)
    {
        if (empty($listeners['job']['enabled'])) {
            return;
        }

        $handler = $listeners['job']['handler'];
        $dispatcher = $this->app['events'];

        $dispatcher->listen(JobProcessing::class, $handler . '@processing');
        $dispatcher->listen(JobProcessed::class, $handler . '@processed');
        $dispatcher->listen(JobFailed::class, $handler . '@failed');
        $dispatcher->listen(JobExceptionOccurred::class, $handler . '@exception');
    }
}