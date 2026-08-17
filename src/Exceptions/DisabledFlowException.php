<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

final class DisabledFlowException extends ImpexException
{
    public static function slug(string $slug): self
    {
        return new self(sprintf(
            'The flow [%s] is disabled. Re-enable it by removing or updating its row in impex_flows.',
            $slug,
        ));
    }
}
