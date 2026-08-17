<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\ListChannelsAction;
use JayI\Impex\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

final class ListChannelsMcpRequest extends Request
{
    protected function rules(): array
    {
        return ListChannelsAction::rules();
    }

    protected function handle(array $validated): ResponseFactory
    {
        return $this->structuredCollection(app(ListChannelsAction::class)->execute());
    }
}
