<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Resources\RunOwnerResource;

final class IndexRunOwnersRequest extends RunRequest
{
    public function persist(): JsonResponse
    {
        return RunOwnerResource::collection($this->run()->owners()->get())->response();
    }
}
