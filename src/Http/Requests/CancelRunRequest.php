<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\CancelRunAction;
use JayI\Impex\Http\Resources\RunResource;

final class CancelRunRequest extends RunRequest
{
    public function rules(): array
    {
        return CancelRunAction::rules();
    }

    public function persist(): JsonResponse
    {
        $run = app(CancelRunAction::class)->execute($this->run(), $this->validated());

        return (new RunResource($run))->response();
    }
}
