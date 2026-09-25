<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListRunStepsAction;
use JayI\Impex\Http\Resources\RunStepResource;
use JayI\Impex\Models\RunStep;
use Laravel\Mcp\ResponseFactory;

final class ListRunStepsMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('viewAny', RunStep::class, [$this->run()]);
    }

    protected function rules(): array
    {
        return ListRunStepsAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $steps = app(ListRunStepsAction::class)->execute($this->run(), $validated);

        return $this->structuredCollection(RunStepResource::collection($steps)->resolve());
    }
}
