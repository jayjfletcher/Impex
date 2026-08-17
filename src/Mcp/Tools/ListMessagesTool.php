<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\ListMessagesMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the data-flow ledger: every payload that has crossed the application boundary, inbound or outbound. Cursor paginated.')]
final class ListMessagesTool extends Tool
{
    public function handle(ListMessagesMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'direction' => $schema->string()->description('inbound or outbound.'),
            'channel' => $schema->string()->description('Only messages on this channel.'),
            'run' => $schema->string()->description('Only messages linked to this run id.'),
            'since' => $schema->string()->description('Only messages at or after this date.'),
            'until' => $schema->string()->description('Only messages at or before this date.'),
            'cursor' => $schema->string()->description('Cursor from a previous page.'),
            'per_page' => $schema->integer()->description('Results per page, 1-200.')->min(1)->max(200),
        ];
    }
}
