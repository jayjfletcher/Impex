<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\Text;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * @return Collection<int, Tool>
 */
function impexServerTools(): Collection
{
    return (new ImpexServer(new FakeTransporter))->createContext()->tools();
}

function impexServerTool(string $name): Tool
{
    return impexServerTools()->firstOrFail(fn (Tool $tool): bool => $tool->name() === $name);
}

/**
 * @return array<string, mixed>
 */
function decodeImpexToolSearchPayload(Response $response): array
{
    $content = $response->content();

    expect($content)->toBeInstanceOf(Text::class);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('exposes only the tool search entry points', function (): void {
    expect(impexServerTools()->map(fn (Tool $tool): string => $tool->name())->all())
        ->toBe(['search_tools', 'execute_tools']);
});

it('finds catalog tools by search term', function (): void {
    $payload = decodeImpexToolSearchPayload(
        impexServerTool('search_tools')->handle(new Request(['query' => 'flows', 'limit' => 5])),
    );

    expect($payload['ok'])->toBeTrue()
        ->and($payload['tools'])->not->toBeEmpty()
        ->and(array_column($payload['tools'], 'name'))->toContain('list-flows-tool');
});

it('browses the catalog with an empty query', function (): void {
    $payload = decodeImpexToolSearchPayload(
        impexServerTool('search_tools')->handle(new Request(['query' => '', 'limit' => 50])),
    );

    expect($payload['ok'])->toBeTrue()
        ->and($payload['tools'])->not->toBeEmpty();
});

it('executes a catalog tool through execute_tools', function (): void {
    config()->set('impex.flows', ['linear' => LinearFlow::class]);

    $responses = impexServerTool('execute_tools')->handle(new Request([
        'calls' => [['name' => 'list-flows-tool', 'arguments' => []]],
    ]));

    $payload = decodeImpexToolSearchPayload(collect($responses)->firstOrFail());

    expect($payload['ok'])->toBeTrue()
        ->and($payload['results'][0]['name'])->toBe('list-flows-tool')
        ->and($payload['results'][0]['isError'])->toBeFalse();
});

it('reports an error for a tool missing from the catalog', function (): void {
    $responses = impexServerTool('execute_tools')->handle(new Request([
        'calls' => [['name' => 'no-such-tool', 'arguments' => []]],
    ]));

    $payload = decodeImpexToolSearchPayload(collect($responses)->firstOrFail());

    expect($payload['ok'])->toBeFalse()
        ->and($payload['results'][0]['isError'])->toBeTrue();
});
