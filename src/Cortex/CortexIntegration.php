<?php

declare(strict_types=1);

namespace JayI\Impex\Cortex;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use JayI\Cortex\CortexServiceProvider;
use JayI\Cortex\Mcp\McpInstructionOverrides;
use JayI\Cortex\Mcp\McpServerRegistry;
use JayI\Cortex\Tools\ToolDescriptionOverrides;
use JayI\Cortex\Tools\ToolRegistry;
use JayI\Impex\Mcp\ImpexServer;
use Laravel\Mcp\Server\Tool;

/**
 * Connects the Impex MCP server to Cortex, when Cortex is installed.
 *
 * - The server is registered with Cortex, so its instructions can be
 *   overridden with versioned, publishable content.
 * - Each tool is registered in Cortex's tool registry under its own name,
 *   so Cortex agents can run and inspect workflows, and its description can
 *   be overridden the same way.
 *
 * Cortex is optional. Nothing here runs unless its service provider is
 * loaded and `impex.cortex.enabled` is true, and every Cortex class is
 * referenced only behind that check — so the server and tools cannot extend
 * Cortex's own base classes, and do its lookups here instead.
 */
final class CortexIntegration
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    public function active(): bool
    {
        return $this->config->get('impex.cortex.enabled', true) === true
            && class_exists(CortexServiceProvider::class)
            && $this->app->getProvider(CortexServiceProvider::class) !== null;
    }

    /**
     * Register with Cortex's registries as they are first resolved, so an
     * application that never touches Cortex pays nothing.
     */
    public function register(): void
    {
        if (! $this->active()) {
            return;
        }

        $this->app->afterResolving(McpServerRegistry::class, function (McpServerRegistry $servers): void {
            if (! $servers->has($this->serverName())) {
                $servers->register($this->serverName(), ImpexServer::class);
            }
        });

        $this->app->afterResolving(ToolRegistry::class, function (ToolRegistry $tools, Container $container): void {
            foreach ($this->tools() as $class) {
                $tool = $container->make($class);

                if ($tool instanceof Tool && ! $tools->has($tool->name())) {
                    $tools->register($tool->name(), $class);
                }
            }
        });
    }

    /**
     * The name the server is registered under in Cortex.
     */
    public function serverName(): string
    {
        return $this->config->string('impex.cortex.server', 'impex');
    }

    /**
     * The tools offered to Cortex: all of them, or those named in config.
     *
     * @return array<int, class-string<Tool>>
     */
    public function tools(): array
    {
        /** @var array<int, string>|null $only */
        $only = $this->config->get('impex.cortex.tools');

        if ($only === null) {
            return ImpexServer::TOOLS;
        }

        return array_values(array_filter(
            ImpexServer::TOOLS,
            fn (string $class): bool => in_array($this->app->make($class)->name(), $only, true),
        ));
    }

    /**
     * The published instructions override for the server, if any.
     */
    public function instructions(): ?string
    {
        if (! $this->active()) {
            return null;
        }

        $name = $this->app->make(McpServerRegistry::class)->nameFor(ImpexServer::class);

        return $name === null ? null : $this->app->make(McpInstructionOverrides::class)->for($name);
    }

    /**
     * The published description override for a tool, if any.
     */
    public function description(string $tool): ?string
    {
        return $this->active() ? $this->app->make(ToolDescriptionOverrides::class)->for($tool) : null;
    }
}
