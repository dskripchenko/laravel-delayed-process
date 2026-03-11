<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Services;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Resources\DelayedProcessResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CallbackDispatcher
{
    public function dispatch(DelayedProcess $process): void
    {
        if (! config('delayed-process.callback.enabled', false)) {
            return;
        }

        if ($process->callback_url === null || $process->callback_url === '') {
            return;
        }

        if (! $process->status->isTerminal()) {
            return;
        }

        $timeout = (int) config('delayed-process.callback.timeout', 10);

        try {
            Http::timeout($timeout)->post($process->callback_url, [
                'uuid' => $process->uuid,
                'status' => $process->status->value,
                'data' => $process->data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Delayed process callback failed', [
                'uuid' => $process->uuid,
                'callback_url' => $process->callback_url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
