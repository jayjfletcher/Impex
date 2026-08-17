<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

use Throwable;

final class PayloadException extends ImpexException
{
    public static function artifactMissing(string $artifactId): self
    {
        return new self(sprintf(
            'The artifact [%s] backing this payload is missing. It may have been pruned before the row referencing it.',
            $artifactId,
        ));
    }

    public static function notSerializable(Throwable $previous): self
    {
        return new self(
            'Impex payloads must be JSON serializable. Return plain arrays and scalars from flow actions.',
            previous: $previous,
        );
    }
}
