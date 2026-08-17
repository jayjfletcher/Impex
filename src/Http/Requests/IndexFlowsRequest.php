<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\JsonResponse;
use JayI\Impex\Actions\ListFlowsAction;
use JayI\Impex\Http\Request;

final class IndexFlowsRequest extends Request
{
    public function rules(): array
    {
        return ListFlowsAction::rules();
    }

    public function persist(): JsonResponse
    {
        return new JsonResponse(['data' => app(ListFlowsAction::class)->execute()]);
    }
}
