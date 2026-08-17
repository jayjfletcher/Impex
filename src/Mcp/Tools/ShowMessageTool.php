<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\ShowMessageMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Show one ledger message, including its headers and body preview.')]
final class ShowMessageTool extends Tool
{
    public function handle(ShowMessageMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('The message id.')->required(),
        ];
    }
}
