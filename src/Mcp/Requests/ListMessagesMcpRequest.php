<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListMessagesAction;
use JayI\Impex\Http\Resources\MessageResource;
use JayI\Impex\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

final class ListMessagesMcpRequest extends Request
{
    protected function rules(): array
    {
        return ListMessagesAction::rules();
    }

    protected function handle(array $validated): ResponseFactory
    {
        $messages = app(ListMessagesAction::class)->execute($validated);

        return $this->structuredCollection(
            MessageResource::collection($messages)->resolve(),
            ['next_cursor' => $messages->nextCursor()?->encode()],
        );
    }
}
