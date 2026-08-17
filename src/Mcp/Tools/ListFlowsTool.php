<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\ListFlowsMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the workflows this application can run, with whether each is currently enabled and its schedule.')]
final class ListFlowsTool extends Tool
{
    public function handle(ListFlowsMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [

        ];
    }
}
