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
    protected function authorize(): bool
    {
        return $this->allows('view', $this->message());
    }

    protected function rules(): array
    {
        return ShowMessageAction::rules() + [
            'message' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $message = app(ShowMessageAction::class)->execute($this->message());

        return Response::structured((new MessageResource($message))->resolve());
    }

    private function message(): Message
    {
        /** @var string $id */
        $id = $this->get('message');

        return Message::query()->findOrFail($id);
    }
}
