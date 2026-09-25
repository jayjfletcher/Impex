<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;

/**
 * An owner was detached from a run. The row is gone, so its fields are kept.
 */
final class RunOwnerDetachedActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
        public string $ownerType,
        public string $ownerId,
        public string $role,
    ) {}
}
