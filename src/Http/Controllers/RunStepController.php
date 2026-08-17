<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\IndexRunStepsRequest;
use JayI\Impex\Models\Run;

final class RunStepController
{
    public function index(IndexRunStepsRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }
}
