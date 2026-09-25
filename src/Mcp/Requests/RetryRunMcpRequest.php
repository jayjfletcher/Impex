<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\RetryRunAction;
use JayI\Impex\Http\Resources\RunResource;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class RetryRunMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('retry', $this->run());
    }

    protected function rules(): array
    {
        return RetryRunAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $run = app(RetryRunAction::class)->execute($this->run());

        return Response::structured((new RunResource($run))->resolve());
    }
}
