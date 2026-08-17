<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum CompensationFailure: string
{
    /**
     * Halt the rollback and surface the compensation failure.
     */
    case Stop = 'stop';

    /**
     * Continue rolling back, reporting failures at the end.
     */
    case Continue = 'continue';
}
