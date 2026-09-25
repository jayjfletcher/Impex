<?php

declare(strict_types=1);

namespace JayI\Impex\Tests;

use Atrium\Atrium\AtriumServiceProvider;
use JayI\Impex\ImpexServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            AtriumServiceProvider::class,
            ImpexServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        // Surface tests cover behaviour; PolicyTest turns authorization on.
        $app['config']->set('impex.authorization', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('impex.cache.store', 'array');
        $app['config']->set('filesystems.default', 'local');
    }

    protected function defineDatabaseMigrations(): void
    {
        // Laravel's own migrations give the owner tests a real model to attach.
        $this->loadLaravelMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }
}
