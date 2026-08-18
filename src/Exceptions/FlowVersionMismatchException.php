<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * The class registered for a flow is not the one the run started with.
 *
 * The recorded history describes code that is no longer what the slug resolves
 * to, so replaying against it would be guesswork.
 */
final class FlowVersionMismatchException extends ImpexException
{
    public static function class(string $slug, string $recorded, string $current): self
    {
        return new self(sprintf(
            'The run started on [%s] but the slug [%s] now resolves to [%s]. The recorded history describes '.
            'the original class, so it cannot be replayed against this one. Drain runs of a flow before '.
            'repointing its slug.',
            $recorded,
            $slug,
            $current,
        ));
    }
}
