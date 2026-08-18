<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\RunFlowMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Start a workflow run. Returns immediately with a pending run — the work is queued, not executed inline. Pass an idempotency key to make a retry safe.')]
final class RunFlowTool extends Tool
{
    public function handle(RunFlowMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'flow' => $schema->string()->description('The registered flow slug.')->required(),
            'arguments' => $schema->array()->description('Positional arguments passed to the flow, in order.'),
            'idempotency_key' => $schema->string()->description('Reusing a key returns the original run instead of starting a second one.'),
            'tags' => $schema->object()->description('Queryable key/value tags to attach to the run.'),
            'version' => $schema->string()->description('Pin the run to a flow version, so later code changes can branch on it.'),
            'expires_in' => $schema->integer()->description('Seconds before the run passes its deadline and unwinds.')->min(1),
            'wait' => $schema->boolean()->description('Drive the run inline and return its terminal state. Only for short flows; bounded by impex.limits.sync_seconds.'),
        ];
    }
}
