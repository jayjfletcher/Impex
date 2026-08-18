<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\ListRunsMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List workflow runs, newest first. Filter by status, flow, trigger, owner, tag, or date range. Cursor paginated.')]
final class ListRunsTool extends Tool
{
    public function handle(ListRunsMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('pending, running, waiting, rolling back, completed, failed, or cancelled.'),
            'flow' => $schema->string()->description('Only runs of this flow slug.'),
            'trigger' => $schema->string()->description('api, mcp, command, schedule, channel, code, or child.'),
            'owner_type' => $schema->string()->description('Morph class of an owning model. Requires owner_id.'),
            'owner_id' => $schema->string()->description('Key of the owning model. Requires owner_type.'),
            'tag' => $schema->object()->description('Tag key/value pairs the run must carry.'),
            'since' => $schema->string()->description('Only runs created at or after this date.'),
            'until' => $schema->string()->description('Only runs created at or before this date.'),
            'parent' => $schema->string()->description('Only child runs of this run id.'),
            'cursor' => $schema->string()->description('Cursor from a previous page.'),
            'per_page' => $schema->integer()->description('Results per page, 1-200.')->min(1)->max(200),
        ];
    }
}
