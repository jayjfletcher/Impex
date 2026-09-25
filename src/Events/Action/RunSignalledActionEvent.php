<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;

/**
 * A signal was sent to a run. `signal` is null when an if-running signal found the run finished.
 */
final class RunSignalledActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Run $run,
        public ?Signal $signal,
    ) {}
}
