<?php

declare(strict_types=1);

namespace JayI\Impex;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use JayI\Atrium\Facades\Atrium;
use JayI\Impex\Atrium\ImpexPlugin;
use JayI\Impex\Channels\ChannelRegistry;
use JayI\Impex\Console\Commands\PruneCommand;
use JayI\Impex\Console\Commands\RunFlowCommand;
use JayI\Impex\Console\Commands\SignalCommand;
use JayI\Impex\Console\Commands\TickCommand;
use JayI\Impex\Contracts\RollbackStrategy;
use JayI\Impex\Cortex\CortexIntegration;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Runtime\BatchRunner;
use JayI\Impex\Runtime\Children;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Runtime\EngineOptions;
use JayI\Impex\Runtime\JobRouter;
use JayI\Impex\Runtime\Rollbacks;
use JayI\Impex\Runtime\StepWriter;
use JayI\Impex\Runtime\Sweeper;
use JayI\Impex\Runtime\Waits;
use JayI\Impex\Support\Locks;
use JayI\Impex\Support\MessageRecorder;
use JayI\Impex\Support\OutboundRecorder;
use JayI\Impex\Support\PayloadStore;
use Laravel\Mcp\Facades\Mcp;

class ImpexServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/impex.php', 'impex');

        $this->app->singleton(FlowRegistry::class);

        $this->app->singleton(PayloadStore::class);

        $this->app->singleton(Locks::class);

        // Each engine collaborator is resolved from the container, so an
        // application can bind its own without forking the package.
        $this->app->singleton(EngineOptions::class);

        $this->app->singleton(JobRouter::class);

        $this->app->singleton(StepWriter::class);

        $this->app->singleton(Children::class);

        $this->app->singleton(Waits::class);

        $this->app->singleton(Sweeper::class);

        // Bind your own to change what a failed run unwinds, and in what order.
        $this->app->singleton(RollbackStrategy::class, Rollbacks::class);

        $this->app->singleton(ChannelRegistry::class);

        $this->app->singleton(MessageRecorder::class);

        $this->app->singleton(OutboundRecorder::class);

        $this->app->singleton(BatchRunner::class);

        $this->app->singleton(Engine::class);

        $this->app->singleton(Impex::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cortex is optional: agents get the Impex tools only when it is loaded.
        $this->app->make(CortexIntegration::class)->register();

        $this->registerPolicies();

        $this->registerRoutes();

        $this->registerAtriumPlugin();

        $this->registerMcpServer();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'impex');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'impex');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/impex.php' => config_path('impex.php'),
        ], ['impex', 'impex-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/impex'),
        ], ['impex', 'impex-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/impex'),
        ], ['impex', 'impex-lang']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['impex', 'impex-migrations']);

        $this->commands([
            PruneCommand::class,
            RunFlowCommand::class,
            SignalCommand::class,
            TickCommand::class,
        ]);

        $this->registerSchedule();
    }

    /**
     * Register each model's policy from `impex.policies`, so an application
     * swaps one by pointing its model at another class there.
     */
    private function registerPolicies(): void
    {
        /** @var array<class-string, class-string> $policies */
        $policies = $this->app->make('config')->get('impex.policies', []);

        foreach ($policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Register the API routes when enabled in the config.
     */
    private function registerRoutes(): void
    {
        if ($this->app->make('config')->get('impex.routes.enabled') !== true) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/impex.php');
    }

    /**
     * Register Impex with the Atrium dashboard.
     *
     * Atrium discovers the plugin from composer.json, so this only honours the
     * config switch that turns the dashboard surface off.
     */
    private function registerAtriumPlugin(): void
    {
        if ($this->app->make('config')->get('impex.ui.enabled') !== true) {
            return;
        }

        Atrium::plugin(ImpexPlugin::class);
    }

    private function registerMcpServer(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        $config = $this->app->make('config');

        if ($config->get('impex.mcp.web.enabled') === true) {
            /** @var array<int, string> $middleware */
            $middleware = $config->get('impex.mcp.web.middleware', []);

            Mcp::web((string) $config->get('impex.mcp.web.route'), ImpexServer::class)
                ->middleware($middleware);
        }

        if ($config->get('impex.mcp.local.enabled') === true) {
            Mcp::local((string) $config->get('impex.mcp.local.handle'), ImpexServer::class);
        }
    }

    /**
     * Sweep due timers every minute, and register any scheduled flows.
     *
     * The sweep is what makes waits longer than the queue's delay ceiling
     * possible, so it is not optional: without it, a run that sleeps for a day
     * never wakes.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make('config');

            if ($config->get('impex.timers.enabled', true) !== false) {
                $schedule->command(TickCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();
            }

            $registry = $this->app->make(FlowRegistry::class);

            foreach (array_keys($registry->all()) as $slug) {
                $cron = $registry->schedule($slug);

                if ($cron === null) {
                    continue;
                }

                $schedule->command(RunFlowCommand::class, [$slug, '--trigger=schedule'])->cron($cron);
            }
        });
    }
}
