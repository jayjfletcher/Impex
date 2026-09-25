<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Timer;

/**
 * Timers are engine state, so reading them needs `view` on the run and no
 * ability changes them.
 */
class TimerPolicy extends Policy
{
    public function viewAny(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'view', $run);
    }

    public function view(Model $user, Timer $timer): bool
    {
        return $this->allowsOnRun($user, 'view', $timer->run);
    }
}
