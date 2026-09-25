<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

/**
 * An owner was attached to a run, or was already attached.
 */
final class RunOwnerAttachedActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
        public RunOwner $owner,
    ) {}
}
