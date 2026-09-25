<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp;

use JayI\Impex\Cortex\CortexIntegration;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Base class for Impex MCP tools.
 *
 * A tool does nothing but hand its request to `persist()`. All behaviour lives
 * in the Action the request wraps, which the HTTP surface calls too.
 */
abstract class Tool extends McpTool
{
    /**
     * Cortex's published description override, when Cortex is installed and
     * one is published, in place of the code-declared description.
     */
    public function description(): string
    {
        return app(CortexIntegration::class)->description($this->name()) ?? parent::description();
    }
}
