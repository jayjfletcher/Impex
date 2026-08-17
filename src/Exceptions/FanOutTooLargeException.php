<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * A fan-out was handed more items than per-item replay can carry.
 */
final class FanOutTooLargeException extends ImpexException
{
    public static function for(int $count, int $cap): self
    {
        return new self(sprintf(
            'fanOut() received %d items, above the configured cap of %d (impex.limits.fan_out_max). '.
            'Replay is O(history) per drive, so %d per-item steps would cost roughly %s step-row reads '.
            'across the run. Use batch() for collections of this size, or raise the cap if the flow is short.',
            $count,
            $cap,
            $count,
            number_format($count ** 2 / 2),
        ));
    }
}
