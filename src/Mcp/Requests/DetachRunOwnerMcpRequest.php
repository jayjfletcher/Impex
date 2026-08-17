<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\DetachRunOwnerAction;
use JayI\Impex\Models\RunOwner;
use Laravel\Mcp\Response;

final class DetachRunOwnerMcpRequest extends RunRequest
{
    protected function rules(): array
    {
        return DetachRunOwnerAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
            'owner' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): Response
    {
        /** @var string $ownerId */
        $ownerId = $validated['owner'];

        $owner = RunOwner::query()->findOrFail($ownerId);

        app(DetachRunOwnerAction::class)->execute($this->run(), $owner);

        return Response::text('Owner detached.');
    }
}
