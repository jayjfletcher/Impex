<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListFlowsAction;
use JayI\Impex\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

final class ListFlowsMcpRequest extends Request
{
    protected function rules(): array
    {
        return ListFlowsAction::rules();
    }

    protected function handle(array $validated): ResponseFactory
    {
        return $this->structuredCollection(app(ListFlowsAction::class)->execute());
    }
}
