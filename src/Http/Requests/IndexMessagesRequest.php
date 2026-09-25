<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListMessagesAction;
use JayI\Impex\Http\Request;
use JayI\Impex\Http\Resources\MessageResource;
use JayI\Impex\Models\Message;

final class IndexMessagesRequest extends Request
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->allows('viewAny', Message::class);
    }

    public function rules(): array
    {
        return ListMessagesAction::rules();
    }

    public function persist(): JsonResponse
    {
        $messages = app(ListMessagesAction::class)->execute($this->validated(), $this->actor());

        return MessageResource::collection($messages)->response();
    }
}
