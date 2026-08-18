<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * A step or run passed the deadline it was given.
 *
 * Deadlines are enforced by the `impex:tick` sweep rather than in-process: a
 * step that has already handed control to an upstream call cannot check a clock,
 * and a Lambda invocation that is killed never gets the chance.
 */
final class DeadlineExceededException extends ImpexException
{
    public static function step(string $name, int $sequence): self
    {
        return new self(sprintf(
            'The step [%s] at sequence %d passed its deadline before completing.',
            $name,
            $sequence,
        ));
    }

    public static function run(string $flow): self
    {
        return new self(sprintf('The run of [%s] passed its deadline before completing.', $flow));
    }
}
