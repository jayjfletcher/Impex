<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ShowRunAction;
use JayI\Impex\Http\Resources\RunResource;

final class ShowRunRequest extends RunRequest
{
    public function authorize(): bool
    {
        return $this->allows('view', $this->run());
    }

    public function rules(): array
    {
        return ShowRunAction::rules();
    }

    public function persist(): JsonResponse
    {
        return (new RunResource(app(ShowRunAction::class)->execute($this->run())))->response();
    }
}
