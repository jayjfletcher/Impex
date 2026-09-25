<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionStartingEvent;

/**
 * The inbound channels are about to be listed.
 */
final class ChannelsListingActionEvent implements ActionStartingEvent
{
    use Dispatchable;
    use SerializesModels;

    //
}
