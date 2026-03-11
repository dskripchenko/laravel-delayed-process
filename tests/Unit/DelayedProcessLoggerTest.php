<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Dskripchenko\DelayedProcess\Services\DelayedProcessLogger;

it('buffers log entries without saving', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'Test message');

    $process->refresh();
    expect($process->logs)->toBe([]);
});

it('flushes buffered logs in single save', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'First message');
    $logger->log('error', 'Second message');
    $logger->flush();

    $process->refresh();
    expect($process->logs)->toHaveCount(2)
        ->and($process->logs[0]['level'])->toBe('INFO')
        ->and($process->logs[0]['message'])->toBe('First message')
        ->and($process->logs[1]['level'])->toBe('ERROR')
        ->and($process->logs[1]['message'])->toBe('Second message');
});

it('strips context when log_sensitive_context is false', function (): void {
    config()->set('delayed-process.log_sensitive_context', false);

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'Message', ['secret' => 'password123']);
    $logger->flush();

    $process->refresh();
    expect($process->logs[0])->not->toHaveKey('context');
});

it('includes context when log_sensitive_context is true', function (): void {
    config()->set('delayed-process.log_sensitive_context', true);

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'Message', ['key' => 'value']);
    $logger->flush();

    $process->refresh();
    expect($process->logs[0]['context'])->toBe(['key' => 'value']);
});

it('does not save on flush when buffer is empty', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->flush();

    $process->refresh();
    expect($process->logs)->toBe([]);
});

it('clears buffer after flush', function (): void {
    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'First batch');
    $logger->flush();

    $logger->log('info', 'Second batch');
    $logger->flush();

    $process->refresh();
    expect($process->logs)->toHaveCount(2)
        ->and($process->logs[0]['message'])->toBe('First batch')
        ->and($process->logs[1]['message'])->toBe('Second batch');
});

it('truncates buffer when exceeding log_buffer_limit', function (): void {
    config()->set('delayed-process.log_buffer_limit', 3);

    $process = DelayedProcess::create([
        'entity' => 'TestEntity',
        'method' => 'testMethod',
        'parameters' => [],
    ]);

    $logger = new DelayedProcessLogger();
    $logger->setProcess($process);
    $logger->log('info', 'Message 1');
    $logger->log('info', 'Message 2');
    $logger->log('info', 'Message 3');
    $logger->log('info', 'Message 4');
    $logger->log('info', 'Message 5');
    $logger->flush();

    $process->refresh();
    expect($process->logs)->toHaveCount(3)
        ->and($process->logs[0]['message'])->toBe('Message 3')
        ->and($process->logs[1]['message'])->toBe('Message 4')
        ->and($process->logs[2]['message'])->toBe('Message 5');
});
