<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Models;

use Dskripchenko\DelayedProcess\Builders\DelayedProcessBuilder;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $entity
 * @property string $method
 * @property array $parameters
 * @property array $data
 * @property array $logs
 * @property ProcessStatus $status
 * @property int $attempts
 * @property int $try
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property Carbon|null $started_at
 * @property int|null $duration_ms
 * @property string|null $callback_url
 * @property int $progress
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static DelayedProcessBuilder query()
 */
class DelayedProcess extends Model
{
    protected $table = 'delayed_processes';

    protected $fillable = [
        'entity',
        'method',
        'parameters',
        'callback_url',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'data' => 'array',
            'logs' => 'array',
            'status' => ProcessStatus::class,
            'attempts' => 'integer',
            'try' => 'integer',
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
            'progress' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function newEloquentBuilder($query): DelayedProcessBuilder
    {
        return new DelayedProcessBuilder($query);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (DelayedProcess $process): void {
            $process->uuid = (string) Str::uuid7();
            $process->status = ProcessStatus::New;
            $process->attempts = (int) config('delayed-process.default_attempts', 5);
            $process->try = 0;
            $process->data = [];
            $process->logs = [];
            $process->progress = 0;
        });
    }
}
