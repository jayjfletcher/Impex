<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Message;

/**
 * The ledger is read-only. A message follows the run it belongs to; one with
 * no run — inbound traffic not yet bound to a run — has no owner, so only
 * the route middleware's operators, with authorization off, can read it.
 */
class MessagePolicy extends Policy
{
    /**
     * Listings are already limited to the messages of runs the user owns.
     */
    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, Message $message): bool
    {
        return $this->allowsOnRun($user, 'view', $message->run);
    }
}
