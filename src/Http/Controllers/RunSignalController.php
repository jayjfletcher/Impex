<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\StoreRunSignalRequest;
use JayI\Impex\Models\Run;

final class RunSignalController
{
    public function store(StoreRunSignalRequest $request, Run $run): JsonResponse
    {
        return $request->persist();
    }
}
