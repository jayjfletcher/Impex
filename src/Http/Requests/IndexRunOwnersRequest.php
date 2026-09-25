<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListRunOwnersAction;
use JayI\Impex\Http\Resources\RunOwnerResource;
use JayI\Impex\Models\RunOwner;

final class IndexRunOwnersRequest extends RunRequest
{
    public function authorize(): bool
    {
        return $this->allows('viewAny', RunOwner::class, [$this->run()]);
    }

    public function persist(): JsonResponse
    {
        return RunOwnerResource::collection(app(ListRunOwnersAction::class)->execute($this->run()))->response();
    }
}
