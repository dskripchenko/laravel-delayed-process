<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Jobs\DelayedProcessJob;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Tests\Fixtures\ContextCarryingJob;
use Dskripchenko\DelayedProcess\Tests\Fixtures\LegacyHandlers;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [LegacyHandlers::class]);
});

function legacyProcess(string $method, array $parameters = []): DelayedProcess
{
    return DelayedProcess::create([
        'entity' => LegacyHandlers::class,
        'method' => $method,
        'parameters' => $parameters,
    ]);
}

it('coerces scalar arguments the way 1.x did, though the runner is a strict file', function (): void {
    // What a JSON body or a form usually carries: strings for an int and a bool
    $process = legacyProcess('typed', ['42', 7, '1']);

    app(ProcessRunnerInterface::class)->run($process);

    $process->refresh();
    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['id' => 42, 'code' => '7', 'flag' => true]);
});

it('stores an API resource as the object it renders, not wrapped in a list', function (): void {
    $process = legacyProcess('resource');

    app(ProcessRunnerInterface::class)->run($process);

    expect($process->refresh()->data)->toBe(['paymentLink' => 'https://pay.example/1']);
});

it('stores an Arrayable as its array', function (): void {
    $process = legacyProcess('collection');

    app(ProcessRunnerInterface::class)->run($process);

    expect($process->refresh()->data)->toBe(['a' => 1]);
});

it('dispatches the job class set in delayed-process.job.class', function (): void {
    Queue::fake();
    config()->set('delayed-process.job.class', ContextCarryingJob::class);

    app(ProcessFactoryInterface::class)->make(LegacyHandlers::class, 'resource');

    Queue::assertPushed(ContextCarryingJob::class);
    Queue::assertNotPushed(DelayedProcessJob::class);
});

it('refuses a job class that is not a queued job', function (): void {
    config()->set('delayed-process.job.class', \stdClass::class);

    app(ProcessFactoryInterface::class)->make(LegacyHandlers::class, 'resource');
})->throws(InvalidArgumentException::class);
