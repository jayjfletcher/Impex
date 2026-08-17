<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListRunStepsAction;
use JayI\Impex\Http\Resources\RunStepResource;

final class IndexRunStepsRequest extends RunRequest
{
    public function rules(): array
    {
        return ListRunStepsAction::rules();
    }

    public function persist(): JsonResponse
    {
        $steps = app(ListRunStepsAction::class)->execute($this->run(), $this->validated());

        return RunStepResource::collection($steps)->response();
    }
}
