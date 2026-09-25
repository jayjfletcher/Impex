<?php

declare(strict_types=1);

namespace JayI\Impex\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * Flow overrides are application-wide settings with no owner. Anyone signed
 * in may read the catalogue they shape; changing one — pausing a flow or
 * rescheduling it — is left to the dashboard, behind Atrium's gate, so the
 * Gate denies it here.
 */
class FlowOverridePolicy extends Policy
{
    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user): bool
    {
        return true;
    }
}
