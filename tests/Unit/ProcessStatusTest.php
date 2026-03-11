<?php

declare(strict_types=1);

use Dskripchenko\DelayedProcess\Enums\ProcessStatus;

it('has correct string values', function (): void {
    expect(ProcessStatus::New->value)->toBe('new')
        ->and(ProcessStatus::Wait->value)->toBe('wait')
        ->and(ProcessStatus::Done->value)->toBe('done')
        ->and(ProcessStatus::Error->value)->toBe('error')
        ->and(ProcessStatus::Expired->value)->toBe('expired')
        ->and(ProcessStatus::Cancelled->value)->toBe('cancelled');
});

it('can transition to wait only from new', function (): void {
    expect(ProcessStatus::New->canTransitionToWait())->toBeTrue()
        ->and(ProcessStatus::Wait->canTransitionToWait())->toBeFalse()
        ->and(ProcessStatus::Done->canTransitionToWait())->toBeFalse()
        ->and(ProcessStatus::Error->canTransitionToWait())->toBeFalse()
        ->and(ProcessStatus::Expired->canTransitionToWait())->toBeFalse()
        ->and(ProcessStatus::Cancelled->canTransitionToWait())->toBeFalse();
});

it('identifies terminal statuses', function (): void {
    expect(ProcessStatus::Done->isTerminal())->toBeTrue()
        ->and(ProcessStatus::Error->isTerminal())->toBeTrue()
        ->and(ProcessStatus::Expired->isTerminal())->toBeTrue()
        ->and(ProcessStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(ProcessStatus::New->isTerminal())->toBeFalse()
        ->and(ProcessStatus::Wait->isTerminal())->toBeFalse();
});

it('identifies active statuses', function (): void {
    expect(ProcessStatus::New->isActive())->toBeTrue()
        ->and(ProcessStatus::Wait->isActive())->toBeTrue()
        ->and(ProcessStatus::Done->isActive())->toBeFalse()
        ->and(ProcessStatus::Error->isActive())->toBeFalse()
        ->and(ProcessStatus::Expired->isActive())->toBeFalse()
        ->and(ProcessStatus::Cancelled->isActive())->toBeFalse();
});

it('identifies cancellable statuses', function (): void {
    expect(ProcessStatus::New->isCancellable())->toBeTrue()
        ->and(ProcessStatus::Wait->isCancellable())->toBeTrue()
        ->and(ProcessStatus::Done->isCancellable())->toBeFalse()
        ->and(ProcessStatus::Error->isCancellable())->toBeFalse()
        ->and(ProcessStatus::Expired->isCancellable())->toBeFalse()
        ->and(ProcessStatus::Cancelled->isCancellable())->toBeFalse();
});
