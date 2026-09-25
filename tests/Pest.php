<?php

declare(strict_types=1);

use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Tests\CortexTestCase;
use JayI\Impex\Tests\TestCase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Transport\JsonRpcResponse;

uses(TestCase::class)->in('Feature', 'Unit');
uses(CortexTestCase::class)->in('Cortex');

/**
 * Call a catalog tool the way a client must now reach it.
 *
 * Every Impex tool sits behind ToolSearch, so the individual tools are no
 * longer registered primitives: they are reachable only through the
 * execute_tools entry point. This resolves the tool's registered name and
 * routes the call through that entry point, returning the inner tool's own
 * response so the usual assertions still apply.
 *
 * @param  class-string<Tool>  $tool
 * @param  array<string, mixed>  $arguments
 */
function mcpTool(string $tool, array $arguments = []): TestResponse
{
    $entryPoints = (new ImpexServer(new FakeTransporter))->createContext()->tools();

    $execute = $entryPoints->firstOrFail(
        fn (Tool $candidate): bool => $candidate->name() === 'execute_tools',
    );

    $primitive = app($tool);

    $envelope = $execute->handle(new Request([
        'calls' => [['name' => $primitive->name(), 'arguments' => $arguments]],
    ]));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) collect($envelope)->firstOrFail()->content(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<string, mixed> $result */
    $result = $decoded['results'][0] ?? [];

    // execute_tools reports each call under results[], which moves the inner
    // tool's own content and structuredContent one level down. Lifting the
    // single result back to the top level keeps the response assertions
    // meaningful against the tool that actually ran.
    return new TestResponse($primitive, JsonRpcResponse::result(1, array_filter([
        'content' => $result['content'] ?? [],
        'structuredContent' => $result['structuredContent'] ?? null,
        'isError' => $result['isError'] ?? false,
    ], fn (mixed $value): bool => $value !== null)));
}

// The dashboard mounts its route at boot, so it needs a test case that enables
// it before the application is created.

/**
 * Use-case Actions with no MCP tool, and why.
 *
 * @var array<string, string>
 */
const MCP_EXCEPTIONS = [
    // Nothing yet: every Action currently has a tool. An artifact download
    // would land here when it is added, because a binary stream has no
    // sensible MCP shape.
];

/**
 * Actions whose name appears in no MCP request class.
 *
 * @return array<int, string>
 */
function parityGaps(): array
{
    $actions = array_map(
        fn (string $path): string => basename($path, '.php'),
        (array) glob(dirname(__DIR__).'/src/Actions/*.php'),
    );

    $mcp = implode("\n", array_map(
        fn (string $path): string => (string) file_get_contents($path),
        (array) glob(dirname(__DIR__).'/src/Mcp/Requests/*.php'),
    ));

    return array_values(array_filter(
        $actions,
        fn (string $action): bool => ! array_key_exists($action, MCP_EXCEPTIONS)
            && ! str_contains($mcp, $action),
    ));
}
