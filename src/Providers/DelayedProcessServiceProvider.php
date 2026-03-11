<?php

declare(strict_types=1);

namespace Dskripchenko\DelayedProcess\Providers;

use Dskripchenko\DelayedProcess\Components\Events\Dispatcher;
use Dskripchenko\DelayedProcess\Console\Commands\ClearOldDelayedProcessCommand;
use Dskripchenko\DelayedProcess\Console\Commands\DelayedProcessCommand;
use Dskripchenko\DelayedProcess\Console\Commands\ExpireProcessesCommand;
use Dskripchenko\DelayedProcess\Console\Commands\MigrateFromV1Command;
use Dskripchenko\DelayedProcess\Console\Commands\UnstuckProcessesCommand;
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessLoggerInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessProgressInterface;
use Dskripchenko\DelayedProcess\Contracts\ProcessRunnerInterface;
use Dskripchenko\DelayedProcess\Services\DelayedProcessFactory;
use Dskripchenko\DelayedProcess\Services\DelayedProcessLogger;
use Dskripchenko\DelayedProcess\Services\DelayedProcessProgress;
use Dskripchenko\DelayedProcess\Services\DelayedProcessRunner;
use Illuminate\Support\ServiceProvider;

final class DelayedProcessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 2) . '/config/delayed-process.php',
            'delayed-process',
        );

        $this->app->bind(ProcessRunnerInterface::class, DelayedProcessRunner::class);
        $this->app->bind(ProcessFactoryInterface::class, DelayedProcessFactory::class);
        $this->app->bind(ProcessLoggerInterface::class, DelayedProcessLogger::class);
        $this->app->bind(ProcessProgressInterface::class, DelayedProcessProgress::class);
        $this->app->singleton(Dispatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2) . '/databases/migrations');

        $this->publishes([
            dirname(__DIR__, 2) . '/config/delayed-process.php' => config_path('delayed-process.php'),
        ], 'delayed-process-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DelayedProcessCommand::class,
                ClearOldDelayedProcessCommand::class,
                UnstuckProcessesCommand::class,
                MigrateFromV1Command::class,
                ExpireProcessesCommand::class,
            ]);
        }
    }
}
