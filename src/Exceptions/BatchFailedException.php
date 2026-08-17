<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

final class BatchFailedException extends ImpexException
{
    public static function threshold(string $action, int $failed, int $total, float $allowed): self
    {
        return new self(sprintf(
            'The batch running [%s] failed %d of %d item(s) (%.2f%%), above the tolerated %.2f%%. '.
            'Inspect the failed rows in impex_batch_items, or raise allowFailures().',
            $action,
            $failed,
            $total,
            $total === 0 ? 0.0 : ($failed / $total) * 100,
            $allowed * 100,
        ));
    }
}
