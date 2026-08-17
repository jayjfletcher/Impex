<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\RetryRunMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Re-queue a drive for a failed or stalled run. Completed steps are not re-executed.')]
final class RetryRunTool extends Tool
{
    public function handle(RetryRunMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->description('The run id.')->required(),
        ];
    }
}
