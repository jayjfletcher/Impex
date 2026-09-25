<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\Run;

/**
 * A batch is one step of its run, so reading it needs `view` on the run and
 * no ability changes it.
 */
class BatchPolicy extends Policy
{
    public function viewAny(Model $user, Run $run): bool
    {
        return $this->allowsOnRun($user, 'view', $run);
    }

    public function view(Model $user, Batch $batch): bool
    {
        return $this->allowsOnRun($user, 'view', $batch->run);
    }
}
