<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListChannelsAction;
use JayI\Impex\Http\Request;

final class IndexChannelsRequest extends Request
{
    public function rules(): array
    {
        return ListChannelsAction::rules();
    }

    public function persist(): JsonResponse
    {
        return new JsonResponse(['data' => app(ListChannelsAction::class)->execute()]);
    }
}
