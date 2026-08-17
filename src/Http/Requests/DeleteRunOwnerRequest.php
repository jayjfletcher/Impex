<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use Illuminate\Http\Response;
use JayI\Impex\Actions\DetachRunOwnerAction;
use JayI\Impex\Models\RunOwner;

final class DeleteRunOwnerRequest extends RunRequest
{
    public function rules(): array
    {
        return DetachRunOwnerAction::rules();
    }

    public function persist(): Response
    {
        $owner = $this->route('owner');

        if (! $owner instanceof RunOwner) {
            abort(404);
        }

        app(DetachRunOwnerAction::class)->execute($this->run(), $owner);

        return new Response(status: 204);
    }
}
