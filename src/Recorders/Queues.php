<?php

namespace Laravel\Pulse\Recorders;

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Laravel\Pulse\Pulse;

/**
 * @internal
 */
class Queues
{
    use Concerns\Ignores, Concerns\Sampling;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        JobReleasedAfterException::class,
        JobFailed::class,
        JobProcessed::class,
        JobProcessing::class,
        JobQueued::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Record the job.
     */
    public function record(JobReleasedAfterException|JobFailed|JobProcessed|JobProcessing|JobQueued $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        [$timestamp, $class, $connection, $uuid, $name] = [
            CarbonImmutable::now()->getTimestamp(),
            $event::class,
            match ($event::class) {
                JobQueued::class => $event->connectionName.':'.($event->job->queue ?? $this->getDefaultQueue($event->connectionName)),
                default => $event->job->getConnectionName().':'.$event->job->getQueue(), // @phpstan-ignore method.nonObject method.nonObject
            },
            match ($event::class) {
                JobQueued::class => $event->payload()['uuid'],
                default => $event->job->uuid(), // @phpstan-ignore method.nonObject
            },
            match ($event::class) {
                JobQueued::class => match (true) {
                    is_string($event->job) => $event->job,
                    method_exists($event->job, 'displayName') => $event->job->displayName(),
                    default => $event->job::class,
                },
                default => $event->job->resolveName(), // @phpstan-ignore method.nonObject
            },
        ];

        $this->pulse->lazy(function () use ($timestamp, $class, $uuid, $name, $connection) {
            if (! $this->shouldSampleDeterministically($uuid) || $this->shouldIgnore($name)) {
                return;
            }

            $this->pulse->record(
                type: match ($class) { // @phpstan-ignore match.unhandled
                    JobQueued::class => 'queued',
                    JobProcessing::class => 'processing',
                    JobProcessed::class => 'processed',
                    JobReleasedAfterException::class => 'released',
                    JobFailed::class => 'failed',
                },
                key: $connection,
                timestamp: $timestamp,
            )->count()->onlyBuckets();
        });
    }

    /**
     * Get the default queue for the connection
     */
    protected function getDefaultQueue(string $connection): string
    {
        return $this->config->get('queue.connections.'.$connection.'.queue', 'default');
    }
}
