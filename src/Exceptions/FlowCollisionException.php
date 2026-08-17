<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * Two packages claimed the same flow slug.
 *
 * Shadowing silently would mean whichever provider booted last wins, and the
 * losing package's runs would quietly execute the wrong class.
 */
final class FlowCollisionException extends ImpexException
{
    public static function slug(string $slug, string $existing, string $incoming): self
    {
        return new self(sprintf(
            'The flow slug [%s] is already registered to [%s], and [%s] tried to claim it. '.
            'Prefix the slug with the package name, or set `impex.flows.%s` in the application '.
            'config to choose explicitly — config always wins over a package registration.',
            $slug,
            $existing,
            $incoming,
            $slug,
        ));
    }
}
