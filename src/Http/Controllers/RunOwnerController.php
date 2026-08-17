<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use JayI\Impex\Http\Requests\DeleteRunOwnerRequest;
use JayI\Impex\Http\Requests\IndexRunOwnersRequest;
use JayI\Impex\Http\Requests\StoreRunOwnerRequest;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

final class RunOwnerController
{
    public function index(IndexRunOwnersRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }

    public function store(StoreRunOwnerRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }

    public function destroy(DeleteRunOwnerRequest $request, Run $run, RunOwner $owner): Response
    {
        return $request->persist();
    }
}
