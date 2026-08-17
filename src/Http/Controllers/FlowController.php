<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\IndexFlowsRequest;
use JayI\Impex\Http\Requests\StoreFlowRunRequest;

final class FlowController
{
    public function index(IndexFlowsRequest $request): JsonResponse
    {
        return $request->persist();
    }

    public function run(StoreFlowRunRequest $request): JsonResponse
    {
        return $request->persist();
    }
}
