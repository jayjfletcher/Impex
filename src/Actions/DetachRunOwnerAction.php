<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

final class DetachRunOwnerAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    public function execute(Run $run, RunOwner $owner): void
    {
        if ($owner->run_id !== $run->getKey()) {
            abort(404);
        }

        $owner->delete();
    }
}
