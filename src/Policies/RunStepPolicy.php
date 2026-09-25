<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

/**
 * Steps are a run's history, written only by the engine, so reading them
 * needs `view` on the run and no ability changes them: the Gate denies
 * create, update and delete.
 */
class RunStepPolicy extends Policy
{
    public function viewAny(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'view', $run);
    }

    public function view(Model $user, RunStep $step): bool
    {
        return $this->allowsOnRun($user, 'view', $step->run);
    }
}
