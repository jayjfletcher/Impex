<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ShowMessageAction;
use JayI\Impex\Http\Resources\MessageResource;
use JayI\Impex\Mcp\Request;
use JayI\Impex\Models\Message;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class ShowMessageMcpRequest extends Request
{
    protected function rules(): array
    {
        return ShowMessageAction::rules() + [
            'message' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        /** @var string $id */
        $id = $validated['message'];

        $message = app(ShowMessageAction::class)->execute(Message::query()->findOrFail($id));

        return Response::structured((new MessageResource($message))->resolve());
    }
}
