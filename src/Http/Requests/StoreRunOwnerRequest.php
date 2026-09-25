<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\AttachRunOwnerAction;
use JayI\Impex\Http\Resources\RunOwnerResource;
use JayI\Impex\Models\RunOwner;

final class StoreRunOwnerRequest extends RunRequest
{
    public function authorize(): bool
    {
        return $this->allows('create', RunOwner::class, [$this->run()]);
    }

    public function rules(): array
    {
        return AttachRunOwnerAction::rules();
    }

    public function persist(): JsonResponse
    {
        $owner = app(AttachRunOwnerAction::class)->execute($this->run(), $this->validated());

        return (new RunOwnerResource($owner))->response()->setStatusCode(201);
    }
}
