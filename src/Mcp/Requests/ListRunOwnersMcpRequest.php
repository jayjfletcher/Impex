<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Http\Resources\RunOwnerResource;
use Laravel\Mcp\ResponseFactory;

final class ListRunOwnersMcpRequest extends RunRequest
{
    protected function rules(): array
    {
        return ['run' => ['required', 'string', 'max:26']];
    }

    protected function handle(array $validated): ResponseFactory
    {
        return $this->structuredCollection(
            RunOwnerResource::collection($this->run()->owners()->get())->resolve(),
        );
    }
}
