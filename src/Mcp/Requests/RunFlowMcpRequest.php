<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\RunFlowAction;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Http\Resources\RunResource;
use JayI\Impex\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class RunFlowMcpRequest extends Request
{
    protected function rules(): array
    {
        return RunFlowAction::rules() + [
            'flow' => ['required', 'string', 'max:191'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        /** @var string $flow */
        $flow = $validated['flow'];

        $run = app(RunFlowAction::class)->execute($flow, $validated, RunTrigger::Mcp);

        return Response::structured((new RunResource($run))->resolve());
    }
}
