<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * A signal wait reached its deadline without the signal arriving.
 *
 * Only thrown when the flow asked for it with `orFail()`. By default a timed
 * out wait returns its default value instead, so the flow can branch on it.
 */
final class SignalTimeoutException extends ImpexException
{
    public static function name(string $name, int $sequence): self
    {
        return new self(sprintf(
            'The wait for signal [%s] at sequence %d timed out.',
            $name,
            $sequence,
        ));
    }
}
