<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Events\ProcessCompleted;
use Dskripchenko\DelayedProcess\Events\ProcessFailed;
use Dskripchenko\DelayedProcess\Events\ProcessStarted;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

final class DelayedProcessRunner implements ProcessRunnerInterface
{
    /**
     * The tracker handlers report to. It has to be the instance the container
     * hands out, or what a handler reports goes to a tracker with no process.
     */
    private readonly ProcessProgressInterface $progress;

    public function __construct(
        private readonly CallableResolver $resolver,
        private readonly ProcessLoggerInterface $logger,
        private readonly CallbackDispatcher $callbackDispatcher = new CallbackDispatcher(),
        ?ProcessProgressInterface $progress = null,
    ) {
        $this->progress = $progress ?? app(ProcessProgressInterface::class);
    }

    public function run(DelayedProcess $process): void
    {
        $process->refresh();

        if ($process->status->isTerminal()) {
            return;
        }

        $claimed = $this->claim($process);

        if ($claimed === null) {
            return;
        }

        $this->logger->setProcess($claimed);
        $this->attachProgress($claimed);

        $claimed->started_at = now();
        $claimed->save();

        $startTime = hrtime(true);

        ProcessStarted::dispatch($claimed);

        try {
            $callable = $this->resolver->resolve($claimed->entity, $claimed->method);
            $result = $callable(...$this->bindArguments($callable, $claimed->parameters));

            $claimed->data = $this->normalizeResult($result);
            $claimed->status = ProcessStatus::Done;
            $claimed->progress = 100;
            $claimed->duration_ms = (int) ((hrtime(true) - $startTime) / 1_000_000);

            ProcessCompleted::dispatch($claimed);
        } catch (\Throwable $e) {
            $claimed->error_message = $this->truncateWithIndicator($e->getMessage(), 1000);
            $claimed->error_trace = $this->truncateWithIndicator($e->getTraceAsString(), 5000);
            $claimed->duration_ms = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $claimed->status = $claimed->try >= $claimed->attempts
                ? ProcessStatus::Error
                : ProcessStatus::New;

            ProcessFailed::dispatch($claimed, $e);
        } finally {
            $this->logger->flush();
            $this->attachProgress(null);
            $claimed->save();
            $this->callbackDispatcher->dispatch($claimed);
        }
    }

    /**
     * Points the progress tracker at the running process, or detaches it.
     * A custom ProcessProgressInterface binding takes part when it has a
     * setProcess() method; the contract itself does not require one.
     */
    private function attachProgress(?DelayedProcess $process): void
    {
        if (method_exists($this->progress, 'setProcess')) {
            $this->progress->setProcess($process);
        }
    }

    private function claim(DelayedProcess $process): ?DelayedProcess
    {
        $affected = DelayedProcess::query()
            ->where('id', $process->id)
            ->where('status', ProcessStatus::New->value)
            ->update([
                'status' => ProcessStatus::Wait->value,
                'try' => $process->try + 1,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return null;
        }

        $process->refresh();

        return $process;
    }

    private function truncateWithIndicator(string $text, int $max): string
    {
        $suffix = '... [truncated]';

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - mb_strlen($suffix)) . $suffix;
    }

    /**
     * Turns the stored parameters into the arguments the handler is called with.
     *
     * - A list is passed positionally, as it always was.
     * - A string-keyed array whose every key names a parameter of the handler
     *   is passed by name: parameters it leaves out take their defaults, and a
     *   required parameter typed with a class is resolved from the container.
     *   An empty array is bound the same way, with nothing to pass by name.
     * - Any other associative array (keys the handler does not declare, or a
     *   mix of integer and string keys) is passed whole as the one argument,
     *   which keeps handlers declared as `handle(array $params)` working.
     *
     * @return array<int|string, mixed>
     */
    private function bindArguments(callable $callable, ?array $parameters): array
    {
        $parameters ??= [];

        if ($parameters !== [] && array_is_list($parameters)) {
            return $parameters;
        }

        $signature = $this->reflect($callable)->getParameters();

        if (! $this->namesParameters($parameters, $signature)) {
            return [$parameters];
        }

        foreach ($signature as $parameter) {
            $name = $parameter->getName();

            if ($parameter->isVariadic() || array_key_exists($name, $parameters)) {
                continue;
            }

            $dependency = $this->resolveDependency($parameter);

            if ($dependency !== null) {
                $parameters[$name] = $dependency;
            }
        }

        return $parameters;
    }

    /**
     * @param  array<int|string, mixed>  $parameters
     * @param  list<\ReflectionParameter>  $signature
     */
    private function namesParameters(array $parameters, array $signature): bool
    {
        $names = [];

        foreach ($signature as $parameter) {
            if (! $parameter->isVariadic()) {
                $names[$parameter->getName()] = true;
            }
        }

        foreach (array_keys($parameters) as $key) {
            if (! is_string($key) || ! isset($names[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A required parameter typed with a class or interface, which the caller
     * did not pass, comes from the container. Anything else is left to PHP:
     * an optional parameter takes its default, and a missing required one
     * fails the call with the usual ArgumentCountError.
     */
    private function resolveDependency(\ReflectionParameter $parameter): ?object
    {
        if ($parameter->isOptional()) {
            return null;
        }

        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();

        if ($class === 'self' || $class === 'static') {
            $class = $parameter->getDeclaringClass()?->getName() ?? $class;
        }

        return app()->make($class);
    }

    private function reflect(callable $callable): \ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new \ReflectionMethod($callable[0], $callable[1]);
        }

        return new \ReflectionFunction(\Closure::fromCallable($callable));
    }

    private function normalizeResult(mixed $result): array
    {
        if ($result === null) {
            return [];
        }

        if (! is_array($result)) {
            return [$result];
        }

        return $result;
    }
}
