<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\RunFlowAction;
use JayI\Impex\Http\Request;
use JayI\Impex\Http\Resources\RunResource;

final class StoreFlowRunRequest extends Request
{
    public function rules(): array
    {
        return RunFlowAction::rules();
    }

    public function persist(): JsonResponse
    {
        /** @var string $flow */
        $flow = $this->route('flow');

        $run = app(RunFlowAction::class)->execute($flow, $this->validated());

        // 202, not 201: the run is queued, never executed inline, so the
        // caller is not held past the gateway's timeout.
        return (new RunResource($run))->response()->setStatusCode(202);
    }
}
