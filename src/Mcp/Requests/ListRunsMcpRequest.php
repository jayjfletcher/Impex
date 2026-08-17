<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListRunsAction;
use JayI\Impex\Http\Resources\RunResource;
use JayI\Impex\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

final class ListRunsMcpRequest extends Request
{
    protected function rules(): array
    {
        return ListRunsAction::rules();
    }

    protected function handle(array $validated): ResponseFactory
    {
        $runs = app(ListRunsAction::class)->execute($validated);

        return $this->structuredCollection(
            RunResource::collection($runs)->resolve(),
            ['next_cursor' => $runs->nextCursor()?->encode()],
        );
    }
}
