<?php

namespace Adata\LaravelJaeger\Listeners;

use Adata\LaravelJaeger\Jaeger;
use Illuminate\Database\Events\QueryExecuted;

class QueryListener
{
    /** @var Jaeger */
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    /**
     * QueryExecuted fires after a query has already run, so we retroactively
     * emit a completed span with the reported duration.
     *
     * @param QueryExecuted $event
     * @return void
     */
    public function handle(QueryExecuted $event)
    {
        $connection = $event->connectionName;

        $this->jaeger->startStop('db.query ' . $connection, (float) $event->time, [
            'db.type'       => 'sql',
            'db.connection' => (string) $connection,
            'db.statement'  => (string) $event->sql,
            'db.time_ms'    => (float) $event->time,
        ]);
    }
}