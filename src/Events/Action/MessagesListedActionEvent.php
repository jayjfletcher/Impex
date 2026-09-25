<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionFinishedEvent;
use JayI\Impex\Models\Message;

/**
 * The ledger was read.
 */
final class MessagesListedActionEvent implements ActionFinishedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  CursorPaginator<int, Message>  $messages
     */
    public function __construct(
        public CursorPaginator $messages,
    ) {}
}
