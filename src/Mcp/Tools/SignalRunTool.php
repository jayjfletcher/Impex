<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\SignalRunMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Deliver a signal to a run. Accepted by any unfinished run - pending, running, or waiting - and a signal sent before the run reaches its wait is held, not lost. Signalling a finished run is an error unless if_running is set.')]
final class SignalRunTool extends Tool
{
    public function handle(SignalRunMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->description('The run id.')->required(),
            'name' => $schema->string()->description('The signal name the flow awaits.')->required(),
            'payload' => $schema->object()->description('Data handed to the flow when it resumes.'),
            'idempotency_key' => $schema->string()->description('Reusing a key will not deliver the signal twice.'),
            'if_running' => $schema->boolean()->description('Treat an already-finished run as a no-op instead of an error.'),
        ];
    }
}
