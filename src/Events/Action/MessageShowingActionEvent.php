<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionStartingEvent;
use JayI\Impex\Models\Message;

/**
 * A ledger message is about to be shown.
 */
final class MessageShowingActionEvent implements ActionStartingEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Message $message,
    ) {}
}
