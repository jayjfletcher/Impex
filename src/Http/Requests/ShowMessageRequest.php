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
    public function rules(): array
    {
        return ShowMessageAction::rules();
    }

    public function persist(): JsonResponse
    {
        $message = $this->route('message');

        if (! $message instanceof Message) {
            abort(404);
        }

        return (new MessageResource(app(ShowMessageAction::class)->execute($message)))->response();
    }
}
