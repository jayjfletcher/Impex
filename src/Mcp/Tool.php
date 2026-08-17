<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp;

use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Base class for Impex MCP tools.
 *
 * A tool does nothing but hand its request to `persist()`. All behaviour lives
 * in the Action the request wraps, which the HTTP surface calls too.
 */
abstract class Tool extends McpTool
{
    //
}
