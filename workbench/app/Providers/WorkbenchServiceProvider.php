<?php

namespace Workbench\App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        config()->set('scramble.info.version', '0.1.0');
        config()->set('scramble.export_path', 'sdk/openapi.json');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Document the workflow API only. The dashboard pages live under
        // Atrium's own prefix and are not part of the API.
        Scramble::routes(function (Route $route): bool {
            return Str::startsWith($route->uri(), 'impex/');
        });

        // The workbench dashboard is open so `composer serve` is usable
        // without logging in. A real application defines a real gate.
        Gate::define('viewAtrium', fn ($user = null): bool => true);
    }
}
