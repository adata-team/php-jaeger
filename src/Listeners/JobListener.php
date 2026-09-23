<?php

namespace Adata\LaravelJaeger\Listeners;

use Adata\LaravelJaeger\Jaeger;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class JobListener
{
    /** @var Jaeger */
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    /**
     * @param JobProcessing $event
     * @return void
     */
    public function processing(JobProcessing $event)
    {
        $name = $this->operation($event->job->resolveName());

        $this->jaeger->start($name, [
            'queue.connection' => (string) $event->connectionName,
            'queue.name'       => (string) $event->job->getQueue(),
            'job.name'         => (string) $event->job->resolveName(),
            'job.attempts'     => (int) $event->job->attempts(),
        ]);
    }

    /**
     * @param JobProcessed $event
     * @return void
     */
    public function processed(JobProcessed $event)
    {
        $this->jaeger->stop($this->operation($event->job->resolveName()), [
            'job.status' => 'processed',
        ]);
    }

    /**
     * @param JobFailed $event
     * @return void
     */
    public function failed(JobFailed $event)
    {
        $this->jaeger->stop($this->operation($event->job->resolveName()), [
            'job.status'    => 'failed',
            'error'         => true,
            'error.message' => $event->exception->getMessage(),
            'error.class'   => get_class($event->exception),
        ]);
    }

    /**
     * @param JobExceptionOccurred $event
     * @return void
     */
    public function exception(JobExceptionOccurred $event)
    {
        $this->jaeger->stop($this->operation($event->job->resolveName()), [
            'job.status'    => 'exception',
            'error'         => true,
            'error.message' => $event->exception->getMessage(),
            'error.class'   => get_class($event->exception),
        ]);
    }

    /**
     * @param string $name
     * @return string
     */
    private function operation($name)
    {
        return 'job ' . $name;
    }
}