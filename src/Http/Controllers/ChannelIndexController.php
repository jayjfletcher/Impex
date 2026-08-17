<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Http\Requests\IndexChannelsRequest;

final class ChannelIndexController
{
    public function __invoke(IndexChannelsRequest $request): JsonResponse
    {
        return $request->persist();
    }
}
