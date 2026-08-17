<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\IndexMessagesRequest;
use JayI\Impex\Http\Requests\ShowMessageRequest;
use JayI\Impex\Models\Message;

final class MessageController
{
    public function index(IndexMessagesRequest $request): JsonResponse
    {
        return $request->persist();
    }

    public function show(ShowMessageRequest $request, Message $message): JsonResponse
    {
        return $request->persist();
    }
}
