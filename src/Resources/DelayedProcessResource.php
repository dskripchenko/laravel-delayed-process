<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Resources;

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DelayedProcess
 */
final class DelayedProcessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'data' => $this->when($this->status->isTerminal(), $this->data),
            'error_message' => $this->when(
                $this->error_message !== null,
                $this->error_message,
            ),
            'is_error_truncated' => $this->when(
                $this->error_message !== null,
                fn () => str_ends_with((string) $this->error_message, '... [truncated]'),
            ),
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'attempts' => $this->attempts,
            'current_try' => $this->try,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
