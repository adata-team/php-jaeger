<?php

namespace Adata\LaravelJaeger;

use Adata\LaravelJaeger\Listeners\CommandListener;
use Adata\LaravelJaeger\Listeners\JobListener;
use Adata\LaravelJaeger\Listeners\QueryListener;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Jaeger\Config;

class LaravelJaegerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register()
    {
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
                return new Jaeger(null);
            }
        });
    }

    /**
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/jaeger.php' => $this->app->configPath('jaeger.php'),
        ], 'config');

        $listeners = (array) $this->app['config']->get('jaeger.listeners', []);

        $this->bootHttp($listeners);
        $this->bootConsole($listeners);
        $this->bootQuery($listeners);
        $this->bootJob($listeners);

        $this->app->terminating(function () {
            if ($this->app->resolved(Jaeger::class)) {
                $this->app->make(Jaeger::class)->finish();
            }
        });
    }

    /**
     * @param array $listeners
     * @return void
     */
    private function bootHttp(array $listeners)
    {
        if (empty($listeners['http']['enabled'])) {
            return;
        }
        if ($this->app->runningInConsole()) {
            return;
        }

        $handler = $listeners['http']['handler'];
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware($handler);
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