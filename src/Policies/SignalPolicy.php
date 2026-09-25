<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;

/**
 * Reading a run's signals needs `view` on the run; delivering one needs
 * `signal`. A delivered signal is history, so nothing updates or deletes it.
 */
class SignalPolicy extends Policy
{
    public function viewAny(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'view', $run);
    }

    public function view(Model $user, Signal $signal): bool
    {
        return $this->allowsOnRun($user, 'view', $signal->run);
    }

    public function create(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'signal', $run);
    }
}
