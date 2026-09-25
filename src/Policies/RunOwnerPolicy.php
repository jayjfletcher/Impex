<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

/**
 * Seeing who owns a run needs `view` on the run; attaching or detaching an
 * owner, which decides who else may see it, needs `share`.
 */
class RunOwnerPolicy extends Policy
{
    public function viewAny(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'view', $run);
    }

    public function view(Model $user, RunOwner $owner): bool
    {
        return $this->allowsOnRun($user, 'view', $owner->run);
    }

    public function create(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'share', $run);
    }

    public function update(Model $user, RunOwner $owner): bool
    {
        return $this->allowsOnRun($user, 'share', $owner->run);
    }

    public function delete(Model $user, RunOwner $owner): bool
    {
        return $this->allowsOnRun($user, 'share', $owner->run);
    }
}
