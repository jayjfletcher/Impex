<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Artifact;
use JayI\Impex\Models\Run;

/**
 * An artifact is a stored payload of a run, read with it and never edited.
 * One with no run has no owner, so it is denied.
 */
class ArtifactPolicy extends Policy
{
    public function view(Model $user, Artifact $artifact): bool
    {
        $run = $artifact->run_id === null ? null : Run::query()->find($artifact->run_id);

        return $this->allowsOnRun($user, 'view', $run);
    }
}
