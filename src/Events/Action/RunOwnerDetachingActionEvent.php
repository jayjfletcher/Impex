<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionStartingEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

/**
 * An owner is about to be detached from a run.
 */
final class RunOwnerDetachingActionEvent implements ActionStartingEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
        public RunOwner $owner,
    ) {}
}
