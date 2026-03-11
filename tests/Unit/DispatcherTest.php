<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Components\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;

it('registers and fires listener by event string', function (): void {
    $dispatcher = new Dispatcher();
    $called = false;

    $ids = $dispatcher->listen(MessageLogged::class, function () use (&$called): void {
        $called = true;
    });

    expect($ids)->toBeArray()->not->toBeEmpty();

    $dispatcher->dispatch(new MessageLogged('info', 'test', []));

    expect($called)->toBeTrue();
});

it('unlistens removes specific listener', function (): void {
    $dispatcher = new Dispatcher();
    $called = false;

    $ids = $dispatcher->listen(MessageLogged::class, function () use (&$called): void {
        $called = true;
    });

    $dispatcher->unlisten(MessageLogged::class, $ids);
    $dispatcher->dispatch(new MessageLogged('info', 'test', []));

    expect($called)->toBeFalse();
});

it('registers listener via closure parameter type', function (): void {
    $dispatcher = new Dispatcher();
    $receivedMessage = null;

    $ids = $dispatcher->listen(function (MessageLogged $event) use (&$receivedMessage): void {
        $receivedMessage = $event->message;
    });

    expect($ids)->toBeArray()->not->toBeEmpty();

    $dispatcher->dispatch(new MessageLogged('info', 'hello-closure', []));

    expect($receivedMessage)->toBe('hello-closure');
});

it('handles wildcard event pattern', function (): void {
    $dispatcher = new Dispatcher();
    $called = false;

    $ids = $dispatcher->listen('test.*', function () use (&$called): void {
        $called = true;
    });

    // Wildcard listeners return empty ids (registered separately)
    expect($ids)->toBeArray();

    $dispatcher->dispatch('test.event', ['payload']);

    expect($called)->toBeTrue();
});

it('supports multiple listeners for same event', function (): void {
    $dispatcher = new Dispatcher();
    $counter = 0;

    $dispatcher->listen(MessageLogged::class, function () use (&$counter): void {
        $counter++;
    });

    $dispatcher->listen(MessageLogged::class, function () use (&$counter): void {
        $counter++;
    });

    $dispatcher->dispatch(new MessageLogged('info', 'test', []));

    expect($counter)->toBe(2);
});
