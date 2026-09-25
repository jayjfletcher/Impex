<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\AttachRunOwnerAction;
use JayI\Impex\Http\Resources\RunOwnerResource;
use JayI\Impex\Models\RunOwner;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class AttachRunOwnerMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('create', RunOwner::class, [$this->run()]);
    }

    protected function rules(): array
    {
        return AttachRunOwnerAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $owner = app(AttachRunOwnerAction::class)->execute($this->run(), $validated);

        return Response::structured((new RunOwnerResource($owner))->resolve());
    }
}
