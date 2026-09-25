<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Run;

/**
 * Answers `$user->can(...)` for runs.
 *
 * A run's owners — any model attached to it through `impex_run_owners`, in
 * any role — may do anything with it. Impex ships no roles or permissions of
 * its own, so everyone else is denied. Any ability an application adds works
 * for owners without a method here. Point `impex.policies` at your own class
 * to replace this one.
 */
class RunPolicy extends Policy
{
    /**
     * Listings are already limited to the runs the user owns.
     */
    public function viewAny(Model $user): bool
    {
        return true;
    }

    /**
     * Anyone signed in may start a flow, and becomes the run's owner. The
     * slug is passed so a replacement policy can restrict which flows.
     */
    public function create(Model $user, ?string $flow = null): bool
    {
        return true;
    }

    public function view(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    public function update(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    public function delete(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    public function cancel(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    public function retry(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    public function signal(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    /**
     * Attaching or detaching owners, which decides who else may see the run.
     */
    public function share(Model $user, Run $run): bool
    {
        return $this->owns($user, $run);
    }

    /**
     * Any other ability is granted to the run's owners.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $ability, array $arguments): bool
    {
        [$user, $run] = $arguments + [null, null];

        return $user instanceof Model && $run instanceof Run && $this->owns($user, $run);
    }
}
