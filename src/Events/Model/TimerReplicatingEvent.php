<?php

declare(strict_types=1);

namespace JayI\Impex\Events\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Contracts\ModelLifecycleEvent;
use JayI\Impex\Models\Timer;

/**
 * The Timer `replicating` Eloquent event.
 */
final class TimerReplicatingEvent implements ModelLifecycleEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Timer $timer) {}

    public function model(): Model
    {
        return $this->timer;
    }

    public function hook(): string
    {
        return 'replicating';
    }
}
