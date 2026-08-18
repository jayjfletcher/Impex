<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\ListRunStepsMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the recorded steps of a run, in replay order. Payloads are not inlined; use the artifact id to fetch a large result.')]
final class ListRunStepsTool extends Tool
{
    public function handle(ListRunStepsMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->description('The run id.')->required(),
            'phase' => $schema->string()->description('forward or rollback. Omit for both.'),
        ];
    }
}
