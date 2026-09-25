<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;

/**
 * A run was cancelled, or had already finished and was left as it was.
 */
final class RunCancelledActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
    ) {}
}
