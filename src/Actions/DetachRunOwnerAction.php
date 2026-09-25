<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Events\Action\RunOwnerDetachedActionEvent;
use JayI\Impex\Events\Action\RunOwnerDetachingActionEvent;
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
        $ownerType = $owner->owner_type;
        $ownerId = $owner->owner_id;
        $role = $owner->role;

        RunOwnerDetachingActionEvent::dispatch($run, $owner);

        $this->perform($run, $owner);

        RunOwnerDetachedActionEvent::dispatch($run, $ownerType, $ownerId, $role);
    }

    private function perform(Run $run, RunOwner $owner): void
    {
        if ($owner->run_id !== $run->getKey()) {
            abort(404);
        }

        $owner->delete();
    }
}
