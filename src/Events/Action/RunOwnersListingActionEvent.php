<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionStartingEvent;
use JayI\Impex\Models\Run;

/**
 * A run's owners are about to be listed.
 */
final class RunOwnersListingActionEvent implements ActionStartingEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
    ) {}
}
