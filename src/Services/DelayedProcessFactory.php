<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Dskripchenko\DelayedProcess\Events\ProcessCreated;
use Dskripchenko\DelayedProcess\Exceptions\InvalidParametersException;
use Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class DelayedProcessFactory implements ProcessFactoryInterface
{
    public function __construct(
        private readonly CallableResolver $resolver,
        private readonly EntityConfigResolver $entityConfigResolver = new EntityConfigResolver(),
    ) {}

    public function make(string $entity, string $method, mixed ...$parameters): DelayedProcess
    {
        return $this->createProcess($entity, $method, null, $parameters);
    }

    public function makeWithCallback(string $entity, string $method, string $callbackUrl, mixed ...$parameters): DelayedProcess
    {
        return $this->createProcess($entity, $method, $callbackUrl, $parameters);
    }

    private function createProcess(string $entity, string $method, ?string $callbackUrl, array $parameters): DelayedProcess
    {
        $this->resolver->validate($entity, $method);
        $this->validateParameters($parameters);

        return DB::transaction(function () use ($entity, $method, $callbackUrl, $parameters): DelayedProcess {
            $attributes = [
                'entity' => $entity,
                'method' => $method,
                'parameters' => $parameters,
            ];

            if ($callbackUrl !== null) {
                $attributes['callback_url'] = $callbackUrl;
            }

            $process = new DelayedProcess($attributes);

            $ttl = config('delayed-process.default_ttl_minutes');

            if ($ttl !== null) {
                $process->expires_at = now()->addMinutes((int) $ttl);
            }

            $process->save();

            $job = $this->makeJob($process);
            $this->configureJob($job, $entity);

            dispatch($job);

            ProcessCreated::dispatch($process);

            return $process;
        });
    }

    private function validateParameters(array $parameters): void
    {
        if (json_encode($parameters) === false) {
            throw InvalidParametersException::notSerializable();
        }
    }

    /**
     * The job that runs the process: DelayedProcessJob, or the class set in
     * `delayed-process.job.class` — for an application that carries its own
     * request context (the client's IP, a session) into the worker. The class
     * takes the process as its only constructor argument and uses Queueable.
     */
    private function makeJob(DelayedProcess $process): ShouldQueue
    {
        $class = (string) config('delayed-process.job.class', DelayedProcessJob::class);

        if (! is_subclass_of($class, ShouldQueue::class)) {
            throw new \InvalidArgumentException("delayed-process.job.class must implement ShouldQueue, {$class} given");
        }

        return new $class($process);
    }

    private function configureJob(ShouldQueue $job, string $entity): void
    {
        $entityConfig = $this->entityConfigResolver->getEntityConfig($entity);

        if (isset($entityConfig['queue'])) {
            $job->onQueue($entityConfig['queue']);
        }

        if (isset($entityConfig['connection'])) {
            $job->onConnection($entityConfig['connection']);
        }

        if (isset($entityConfig['timeout']) && property_exists($job, 'timeout')) {
            $job->timeout = (int) $entityConfig['timeout'];
        }
    }
}
