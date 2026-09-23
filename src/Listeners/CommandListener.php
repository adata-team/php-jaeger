<?php

namespace Adata\LaravelJaeger\Listeners;

use Adata\LaravelJaeger\Jaeger;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

class CommandListener
{
    /** @var Jaeger */
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    /**
     * @param CommandStarting $event
     * @return void
     */
    public function starting(CommandStarting $event)
    {
        if ($event->command === null || $event->command === '') {
            return;
        }

        $this->jaeger->start('artisan ' . $event->command, [
            'artisan.command' => (string) $event->command,
            'artisan.args'    => $this->safeStringify($event->input),
        ]);
    }

    /**
     * @param CommandFinished $event
     * @return void
     */
    public function finished(CommandFinished $event)
    {
        if ($event->command === null || $event->command === '') {
            return;
        }

        $this->jaeger->stop('artisan ' . $event->command, [
            'artisan.exit_code' => (int) $event->exitCode,
        ]);
    }

    /**
     * @param mixed $input
     * @return string
     */
    private function safeStringify($input)
    {
        if ($input === null) {
            return '';
        }
        if (is_object($input) && method_exists($input, '__toString')) {
            return (string) $input;
        }
        return '';
    }
}