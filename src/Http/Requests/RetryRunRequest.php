<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\RetryRunAction;
use JayI\Impex\Http\Resources\RunResource;

final class RetryRunRequest extends RunRequest
{
    public function authorize(): bool
    {
        return $this->allows('retry', $this->run());
    }

    public function rules(): array
    {
        return RetryRunAction::rules();
    }

    public function persist(): JsonResponse
    {
        $run = app(RetryRunAction::class)->execute($this->run());

        return (new RunResource($run))->response()->setStatusCode(202);
    }
}
