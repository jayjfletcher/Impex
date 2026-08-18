<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum RollbackFailure: string
{
    /**
     * Halt the rollback and surface the rollback failure.
     */
    case Halt = 'halt';

    /**
     * Continue rolling back, reporting failures at the end.
     */
    case Continue = 'continue';
}
