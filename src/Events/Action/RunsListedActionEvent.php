<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Run;

/**
 * Runs were listed.
 */
final class RunsListedActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  CursorPaginator<int, Run>  $runs
     */
    public function __construct(
        public CursorPaginator $runs,
    ) {}
}
