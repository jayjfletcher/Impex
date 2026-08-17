<?php

declare(strict_types=1);

use JayI\Impex\Tests\TestCase;
use JayI\Impex\Tests\UiTestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// The dashboard mounts its route at boot, so it needs a test case that enables
// it before the application is created.
uses(UiTestCase::class)->in('Ui');

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
