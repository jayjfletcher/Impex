<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ShowMessageAction;
use JayI\Impex\Http\Request;
use JayI\Impex\Http\Resources\MessageResource;
use JayI\Impex\Models\Message;

final class ShowMessageRequest extends Request
{
    public function authorize(): bool
    {
        return $this->allows('view', $this->message());
    }

    public function rules(): array
    {
        return ShowMessageAction::rules();
    }

    public function persist(): JsonResponse
    {
        return (new MessageResource(app(ShowMessageAction::class)->execute($this->message())))->response();
    }

    private function message(): Message
    {
        $message = $this->route('message');

        if (! $message instanceof Message) {
            abort(404);
        }

        return $message;
    }
}
