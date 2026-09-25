<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\DetachRunOwnerAction;
use JayI\Impex\Models\RunOwner;
use Laravel\Mcp\Response;

final class DetachRunOwnerMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('delete', $this->owner());
    }

    protected function rules(): array
    {
        return DetachRunOwnerAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
            'owner' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): Response
    {
        app(DetachRunOwnerAction::class)->execute($this->run(), $this->owner());

        return Response::text('Owner detached.');
    }

    private function owner(): RunOwner
    {
        /** @var string $id */
        $id = $this->get('owner');

        return RunOwner::query()->findOrFail($id);
    }
}
