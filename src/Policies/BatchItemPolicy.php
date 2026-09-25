<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\BatchItem;

/**
 * Items follow their batch, which follows its run: reading them needs `view`
 * on the batch, and no ability changes them.
 */
class BatchItemPolicy extends Policy
{
    public function viewAny(Model $user, Batch $batch): bool
    {
        return Gate::forUser($user)->allows('view', $batch);
    }

    public function view(Model $user, BatchItem $item): bool
    {
        return Gate::forUser($user)->allows('view', $item->batch);
    }
}
