<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

/**
 * A run's owners were listed.
 */
final class RunOwnersListedActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, RunOwner>  $owners
     */
    public function __construct(
        public Run $run,
        public Collection $owners,
    ) {}
}
