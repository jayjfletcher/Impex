<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\CancelRunAction;
use JayI\Impex\Http\Resources\RunResource;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class CancelRunMcpRequest extends RunRequest
{
    protected function rules(): array
    {
        return CancelRunAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $run = app(CancelRunAction::class)->execute($this->run(), $validated);

        return Response::structured((new RunResource($run))->resolve());
    }
}
