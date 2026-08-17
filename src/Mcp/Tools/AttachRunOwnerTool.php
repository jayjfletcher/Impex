<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JayI\Impex\Mcp\Requests\AttachRunOwnerMcpRequest;
use JayI\Impex\Mcp\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Give a model a stake in a run. The host application decides what customers, teams and users are; this only records the link.')]
final class AttachRunOwnerTool extends Tool
{
    public function handle(AttachRunOwnerMcpRequest $request): Response|ResponseFactory
    {
        return $request->persist();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->description('The run id.')->required(),
            'owner_type' => $schema->string()->description('Morph class of the owning model.')->required(),
            'owner_id' => $schema->string()->description('Key of the owning model.')->required(),
            'role' => $schema->string()->description('The role held, for example customer, team, user, or viewer.')->required(),
        ];
    }
}
