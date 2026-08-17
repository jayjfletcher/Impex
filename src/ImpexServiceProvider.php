<?php

declare(strict_types=1);

namespace JayI\Impex;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JayI\Impex\Channels\ChannelRegistry;
use JayI\Impex\Console\Commands\ImpexCommand;
use JayI\Impex\Console\Commands\PruneCommand;
use JayI\Impex\Console\Commands\RunFlowCommand;
use JayI\Impex\Console\Commands\SignalCommand;
use JayI\Impex\Console\Commands\TickCommand;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Http\Controllers\UiController;
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Runtime\BatchRunner;
use JayI\Impex\Runtime\Engine;
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
        $this->registerRoutes();

        $this->registerUiRoutes();

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

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/impex'),
        ], ['impex', 'impex-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['impex', 'impex-migrations']);

        $this->commands([
            ImpexCommand::class,
            PruneCommand::class,
            RunFlowCommand::class,
            SignalCommand::class,
            TickCommand::class,
        ]);

        $this->registerSchedule();
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
     * Mount the dashboard when enabled in the config.
     *
     * Ships disabled and admin-only by intent: the dashboard renders every
     * payload that has crossed the application boundary.
     */
    private function registerUiRoutes(): void
    {
        $config = $this->app->make('config');

        if ($config->get('impex.ui.enabled') !== true) {
            return;
        }

        /** @var array<int, string> $middleware */
        $middleware = $config->get('impex.ui.middleware', []);

        $path = trim((string) $config->get('impex.ui.path', 'impex/ui'), '/');

        Route::middleware($middleware)
            ->get($path.'/{view?}', UiController::class)
            ->where('view', '.*')
            ->name('impex.ui');
    }

    /**
     * Register the Impex MCP server transports enabled in the config.
     *
     * Both ship disabled. The server triggers and cancels workflows and reads
     * every recorded payload, so the web transport needs auth middleware before
     * it is exposed.
     */
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
