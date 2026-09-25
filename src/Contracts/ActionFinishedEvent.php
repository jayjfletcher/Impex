<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Fired when an action finishes successfully.
 *
 * Dispatched after the surrounding transaction commits, so a listener never
 * hears about work that was rolled back. An action that throws fires no
 * finished event. Listen to this interface to see every action finish.
 */
interface ActionFinishedEvent extends ShouldDispatchAfterCommit
{
    //
}
