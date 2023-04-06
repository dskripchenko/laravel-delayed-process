<?php

namespace Dskripchenko\DelayedProcess\Providers;

use Dskripchenko\DelayedProcess\Console\Commands\ClearOldDelayedProcessCommand;
use Dskripchenko\DelayedProcess\Console\Commands\DelayedProcessCommand;
use Illuminate\Support\ServiceProvider;

class DelayedProcessServiceProvider extends ServiceProvider
{
    /**
     * @var array|string[]
     */
    protected array $commands = [
        DelayedProcessCommand::class,
        ClearOldDelayedProcessCommand::class,
    ];

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2) . '/databases/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
    }
}