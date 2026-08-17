<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\CancelRunRequest;
use JayI\Impex\Http\Requests\IndexRunsRequest;
use JayI\Impex\Http\Requests\RetryRunRequest;
use JayI\Impex\Http\Requests\ShowRunRequest;
use JayI\Impex\Models\Run;

final class RunController
{
    public function index(IndexRunsRequest $request): JsonResponse
    {
        return $request->persist();
    }

    public function show(ShowRunRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }

    public function cancel(CancelRunRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }

    public function retry(RetryRunRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }
}
