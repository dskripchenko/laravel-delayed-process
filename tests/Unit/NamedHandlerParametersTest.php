<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Enums\ProcessStatus;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Tests\Fixtures\AllowedService;
use Dskripchenko\DelayedProcess\Tests\Fixtures\NamedParamsService;

beforeEach(function (): void {
    config()->set('delayed-process.allowed_entities', [
        AllowedService::class,
        NamedParamsService::class,
    ]);
});

function runNamed(string $method, array $parameters, string $entity = NamedParamsService::class): DelayedProcess
{
    $process = DelayedProcess::create([
        'entity' => $entity,
        'method' => $method,
        'parameters' => $parameters,
    ]);
    $process->attempts = 1;
    $process->save();

    app(ProcessRunnerInterface::class)->run($process);

    return $process->refresh();
}

it('passes string keys to the handler parameters of the same name', function (): void {
    $process = runNamed('handle', ['model' => 'App\\Models\\Article']);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'App\\Models\\Article']);
});

it('binds by name regardless of the key order', function (): void {
    $process = runNamed('export', ['format' => 'csv', 'model' => 'post', 'limit' => 5]);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'post', 'limit' => 5, 'format' => 'csv']);
});

it('leaves parameters that are not passed to their defaults', function (): void {
    $process = runNamed('export', ['model' => 'post', 'format' => 'xlsx']);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'post', 'limit' => 10, 'format' => 'xlsx']);
});

it('resolves a class-typed parameter that is not passed from the container', function (): void {
    $process = runNamed('withDependency', ['model' => 'post']);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'post', 'counter' => 'from-container']);
});

it('resolves dependencies of a handler started without parameters', function (): void {
    $process = runNamed('onlyDependency', []);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['counter' => 'from-container']);
});

it('passes array values by name, as an admin row action sends ids', function (): void {
    $process = runNamed('rows', ['ids' => [1, 2, 3]]);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['ids' => [1, 2, 3], 'scope' => 'all']);
});

it('keeps passing a list positionally', function (): void {
    $process = runNamed('export', ['post', 7, 'pdf']);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'post', 'limit' => 7, 'format' => 'pdf']);
});

it('keeps passing a list into a variadic handler', function (): void {
    $process = runNamed('variadic', ['a', 'b', 'c']);

    expect($process->data)->toBe(['prefix' => 'a', 'rest' => ['b', 'c']]);
});

it('passes an associative array whose keys are not parameters as the one argument', function (): void {
    $process = runNamed('whole', ['key' => 'value', 'foo' => 'bar']);

    expect($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['params' => ['key' => 'value', 'foo' => 'bar']]);
});

it('passes the array whole when only some keys are parameters', function (): void {
    $process = runNamed('whole', ['params' => 'x', 'other' => 'y']);

    expect($process->data)->toBe(['params' => ['params' => 'x', 'other' => 'y']]);
});

it('passes an array with mixed integer and string keys whole', function (): void {
    $process = runNamed('whole', [0 => 'a', 'b' => 'c']);

    expect($process->data)->toBe(['params' => [0 => 'a', 'b' => 'c']]);
});

it('does not bind a key onto a variadic parameter by name', function (): void {
    $process = runNamed('whole', ['rest' => 'x']);

    expect($process->data)->toBe(['params' => ['rest' => 'x']]);
});

it('fails the process when a required parameter is missing', function (): void {
    $process = runNamed('export', ['limit' => 5]);

    expect($process->status)->toBe(ProcessStatus::Error)
        ->and($process->error_message)->toContain('model');
});

it('still uses the default of an optional parameter when nothing is passed', function (): void {
    $process = runNamed('handle', [], AllowedService::class);

    expect($process->data)->toBe(['result' => 'default']);
});

it('runs a handler started with named arguments through the factory and the queue', function (): void {
    config()->set('queue.default', 'sync');

    $params = ['model' => 'App\\Models\\Article'];

    $process = app(ProcessFactoryInterface::class)
        ->make(NamedParamsService::class, 'handle', ...$params)
        ->refresh();

    expect($process->parameters)->toBe($params)
        ->and($process->status)->toBe(ProcessStatus::Done)
        ->and($process->data)->toBe(['model' => 'App\\Models\\Article']);
});
