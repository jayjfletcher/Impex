<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

use JayI\Impex\Flows\Flow;

final class UnknownFlowException extends ImpexException
{
    public static function slug(string $slug): self
    {
        return new self(sprintf('No flow is registered under [%s].', $slug));
    }

    public static function notAFlow(string $class): self
    {
        return new self(sprintf(
            'The flow [%s] must extend %s and declare a public handle() method.',
            $class,
            Flow::class,
        ));
    }
}
