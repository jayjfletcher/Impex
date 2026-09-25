<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Action;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ActionStartingEvent;

/**
 * The ledger is about to be read.
 */
final class MessagesListingActionEvent implements ActionStartingEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public array $filters,
        public ?Model $viewer = null,
    ) {}
}
