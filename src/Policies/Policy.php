<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

/**
 * Shared checks for the bundled policies.
 *
 * Each policy is registered from `impex.policies`, so an application swaps
 * one by pointing its model at another class there.
 */
abstract class Policy
{
    /**
     * Whether the user is one of the run's owners, in any role.
     */
    protected function owns(Model $user, Run $run): bool
    {
        $type = $user->getMorphClass();
        $id = (string) $user->getKey();

        if ($run->relationLoaded('owners')) {
            return $run->owners->contains(
                fn (RunOwner $owner): bool => $owner->owner_type === $type && $owner->owner_id === $id,
            );
        }

        return $run->owners()->where('owner_type', $type)->where('owner_id', $id)->exists();
    }

    /**
     * Ask the Gate about the parent run, so a model that belongs to a run
     * follows whichever run policy is registered. A record with no run —
     * a channel message not yet bound to one, say — has no owner to ask.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function allowsOnRun(Model $user, string $ability, ?Run $run, array $arguments = []): bool
    {
        return $run instanceof Run && Gate::forUser($user)->allows($ability, [$run, ...$arguments]);
    }
}
