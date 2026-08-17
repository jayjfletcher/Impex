<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use JayI\Impex\Runtime\StepDeadline;

/**
 * An action that can stop partway and be resumed from a checkpoint.
 *
 * Implement this for any work whose size is not known in advance — seeding a
 * batch from a million-row cursor, draining a paginated upstream, importing a
 * large file. The engine hands back the cursor the action last yielded.
 */
interface Resumable
{
    /**
     * Receive the checkpoint from the previous invocation, if any, and the
     * deadline for this one.
     */
    public function withCheckpoint(?string $cursor, StepDeadline $deadline): void;
}
