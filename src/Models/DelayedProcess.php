<?php

namespace Dskripchenko\DelayedProcess\Models;

use App\Jobs\DelayedProcess\DelayedProcessJob;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Dskripchenko\DelayedProcess\Resources\DelayedProcess as DelayedProcessResource;

/**
 * @property integer $id
 * @property string $uuid
 * @property string $entity
 * @property string $method
 * @property array $parameters
 * @property array $data
 * @property array $logs
 * @property string $status
 * @property integer $attempts
 * @property integer $try
 *
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DelayedProcess extends Model
{

    //pipeline: NEW ->  WAIT -> (DONE|ERROR)
    public const STATUS_NEW = 'new';
    public const STATUS_WAIT = 'wait';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    public const DEFAULT_ATTEMPTS = 5;

    /**
     * @var string[]
     */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'parameters' => 'array',
        'data' => 'array',
        'logs' => 'array',
    ];

    /**
     * @var string[]
     */
    protected $fillable = [
        'uuid',
        'entity', 'method',
        'parameters', 'data', 'logs',
        'status',
        'attempts', 'try'
    ];

    /**
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(static function (DelayedProcess $process) {
            $process->uuid = Str::uuid();
            $process->status = static::STATUS_NEW;
            $process->attempts = static::DEFAULT_ATTEMPTS;
            $process->try = 0;
            $process->data = [];
            $process->logs = [];
        });
    }

    /**
     * @return callable
     */
    protected function getCallable(): callable
    {
        if ($this->entity && $this->method) {
            return [app($this->entity), $this->method];
        }

        if ($this->method) {
            return $this->method;
        }

        if ($this->entity) {
            return app($this->entity);
        }

        return static function (...$parameters) {
            return $parameters;
        };
    }

    /**
     * @return array|array[]
     */
    protected function getParameters(): array
    {
        if (!$this->parameters) {
            return [];
        }

        if (!is_array($this->parameters)) {
            return [$this->parameters];
        }

        if (array_is_associative($this->parameters)) {
            return [$this->parameters];
        }

        return $this->parameters;
    }

    /**
     * @return array
     */
    protected function process(): array
    {
        $callable = $this->getCallable();
        $parameters = $this->getParameters();
        $result = $callable(...$parameters);

        if (is_null($result)) {
            return [];
        }

        if (!is_array($result)) {
            return [$result];
        }

        return $result;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $this->refresh();
        if ($this->status === static::STATUS_WAIT) {
            return;
        }

        $this->status = static::STATUS_WAIT;
        ++$this->try;
        $this->save();
        try {
            $this->data = $this->process();
            $this->status = static::STATUS_DONE;
            $this->save();
        }
        catch (Exception $exception) {
            $status = static::STATUS_NEW;
            if ($this->try >= $this->attempts) {
                $status = static::STATUS_ERROR;
            }
            $this->status = $status;
            $this->save();
        }
    }

    /**
     * @param string $entity
     * @param string $method
     * @param array $parameters
     * @return static
     */
    public static function make(string $entity, string $method, ...$parameters): DelayedProcess
    {
        $instance = new static([
            'entity' => $entity,
            'method' => $method,
            'parameters' => $parameters,
        ]);
        $instance->save();

        DelayedProcessJob::dispatch($instance);

        return $instance;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d H:i:s');
        $msg = [
            'Level' => strtoupper($level),
            'Date' => date('Y-m-d H:i:s'),
            'Message' => $message,
        ];

        if ($message) {
            $msg['Context'] = $context;
        }

        $this->logs = array_merge_deep($this->logs, [
            uniqid("{$date}-", false)=> $msg
        ]);

        $this->save();
    }

    /**
     * @return DelayedProcessResource
     */
    public function toResponse(): DelayedProcessResource
    {
        return new DelayedProcessResource($this);
    }
}
