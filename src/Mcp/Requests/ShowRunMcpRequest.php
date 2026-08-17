<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ShowRunAction;
use JayI\Impex\Http\Resources\RunResource;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class ShowRunMcpRequest extends RunRequest
{
    protected function rules(): array
    {
        return ShowRunAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $run = app(ShowRunAction::class)->execute($this->run());

        return Response::structured((new RunResource($run))->resolve());
    }
}
