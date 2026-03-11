<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

final class DelayedProcessLogger implements ProcessLoggerInterface
{
    private ?DelayedProcess $process = null;

    /** @var list<array{level: string, date: string, message: string, context: array}> */
    private array $buffer = [];

    public function setProcess(DelayedProcess $process): void
    {
        $this->process = $process;
        $this->buffer = [];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'level' => strtoupper($level),
            'date' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];

        $logContext = (bool) config('delayed-process.log_sensitive_context', false);

        if ($logContext && $context !== []) {
            $entry['context'] = $context;
        }

        $this->buffer[] = $entry;

        $limit = (int) config('delayed-process.log_buffer_limit', 500);

        if ($limit > 0 && count($this->buffer) > $limit) {
            $this->buffer = array_slice($this->buffer, -$limit);
        }
    }

    public function flush(): void
    {
        if ($this->process === null || $this->buffer === []) {
            return;
        }

        $existing = $this->process->logs ?? [];
        $this->process->logs = array_merge($existing, $this->buffer);
        $this->process->save();
        $this->buffer = [];
    }
}
