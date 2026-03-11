<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Builders;

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<DelayedProcess>
 */
final class DelayedProcessBuilder extends Builder
{
    public function whereNew(): self
    {
        return $this->where('status', ProcessStatus::New->value);
    }

    public function whereStuck(?int $timeoutMinutes = null): self
    {
        $timeout = $timeoutMinutes ?? (int) config('delayed-process.stuck_timeout_minutes', 60);

        return $this
            ->where('status', ProcessStatus::Wait->value)
            ->where('updated_at', '<=', now()->subMinutes($timeout));
    }

    public function whereTerminal(): self
    {
        return $this->whereIn('status', [
            ProcessStatus::Done->value,
            ProcessStatus::Error->value,
            ProcessStatus::Expired->value,
            ProcessStatus::Cancelled->value,
        ]);
    }

    public function whereExpired(): self
    {
        return $this
            ->whereIn('status', [
                ProcessStatus::New->value,
                ProcessStatus::Wait->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * @param array<int, string> $uuids
     */
    public function whereUuids(array $uuids): self
    {
        return $this->whereIn('uuid', $uuids);
    }

    public function olderThanDays(int $days): self
    {
        return $this->where('created_at', '<=', now()->subDays($days));
    }

    /**
     * Cancel a process by UUID. Only cancellable (new, wait) processes can be cancelled.
     */
    public function cancel(string $uuid): bool
    {
        $affected = $this->newQuery()
            ->where('uuid', $uuid)
            ->whereIn('status', [
                ProcessStatus::New->value,
                ProcessStatus::Wait->value,
            ])
            ->update(['status' => ProcessStatus::Cancelled->value]);

        return $affected > 0;
    }

    /**
     * Atomically claim a process for execution.
     * Uses UPDATE ... WHERE status='new' to prevent race conditions.
     *
     * @return DelayedProcess|null The claimed process, or null if none available.
     */
    public function claimForExecution(): ?DelayedProcess
    {
        $process = $this
            ->whereNew()
            ->orderBy('try', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($process === null) {
            return null;
        }

        $affected = $process->newQuery()
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
}
