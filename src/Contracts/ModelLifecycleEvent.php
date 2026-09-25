<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Every Eloquent lifecycle event of every Impex model.
 *
 * Listen to this interface to see them all: `Event::listen(ModelLifecycleEvent::class, ...)`.
 */
interface ModelLifecycleEvent
{
    /**
     * The model the event is about.
     */
    public function model(): Model;

    /**
     * The lifecycle hook: creating, created, saving, ...
     */
    public function hook(): string;
}
