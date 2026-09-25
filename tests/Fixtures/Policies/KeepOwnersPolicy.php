<?php

declare(strict_types=1);

namespace JayI\Impex\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\RunOwner;
use JayI\Impex\Policies\RunOwnerPolicy;

/**
 * Owners may be attached but never detached.
 */
final class KeepOwnersPolicy extends RunOwnerPolicy
{
    public function delete(Model $user, RunOwner $owner): bool
    {
        return false;
    }
}
