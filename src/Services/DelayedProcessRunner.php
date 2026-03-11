<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Events\ProcessCompleted;
use Dskripchenko\DelayedProcess\Events\ProcessFailed;
use Dskripchenko\DelayedProcess\Events\ProcessStarted;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

final class DelayedProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private readonly CallableResolver $resolver,
        private readonly ProcessLoggerInterface $logger,
        private readonly CallbackDispatcher $callbackDispatcher = new CallbackDispatcher(),
        private readonly DelayedProcessProgress $progress = new DelayedProcessProgress(),
    ) {}

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
        $this->progress->setProcess($claimed);

        $claimed->started_at = now();
        $claimed->save();

        $startTime = hrtime(true);

        ProcessStarted::dispatch($claimed);

        try {
            $callable = $this->resolver->resolve($claimed->entity, $claimed->method);
            $parameters = $this->normalizeParameters($claimed->parameters);
            $result = $callable(...$parameters);

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
            $claimed->save();
            $this->callbackDispatcher->dispatch($claimed);
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

    private function normalizeParameters(?array $parameters): array
    {
        if ($parameters === null || $parameters === []) {
            return [];
        }

        if (! array_is_list($parameters)) {
            return [$parameters];
        }

        return $parameters;
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
