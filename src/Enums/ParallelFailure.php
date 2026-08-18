<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum ParallelFailure: string
{
    /**
     * Fail the block as soon as any branch fails.
     */
    case FailFast = 'fail_fast';

    /**
     * Let every branch settle, then fail if any did.
     */
    case SettleAll = 'settle_all';
}
