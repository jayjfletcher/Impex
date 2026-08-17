<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Exception;

/**
 * Control-flow signal: the replay reached work that has not finished yet.
 *
 * The engine catches this and returns. It is deliberately not an ImpexException
 * and must never be caught by flow code — doing so breaks the replay contract.
 *
 * @internal
 */
final class Suspended extends Exception
{
    public static function make(): self
    {
        return new self('The flow suspended awaiting recorded work.');
    }
}
