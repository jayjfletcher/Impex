<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListRunOwnersAction;
use JayI\Impex\Http\Resources\RunOwnerResource;
use JayI\Impex\Models\RunOwner;
use Laravel\Mcp\ResponseFactory;

final class ListRunOwnersMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('viewAny', RunOwner::class, [$this->run()]);
    }

    protected function rules(): array
    {
        return ListRunOwnersAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        return $this->structuredCollection(
            RunOwnerResource::collection(app(ListRunOwnersAction::class)->execute($this->run()))->resolve(),
        );
    }
}
