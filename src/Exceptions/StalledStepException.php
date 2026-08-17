<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * A resumable step asked to be resumed but is not making progress.
 */
final class StalledStepException extends ImpexException
{
    public static function cursor(string $action, ?string $cursor): self
    {
        return new self(sprintf(
            'The action [%s] yielded the same cursor [%s] twice, so it is not making progress. '.
            'Advance the cursor past the work already done before yielding.',
            $action,
            $cursor ?? 'null',
        ));
    }

    public static function resumptions(string $action, int $resumptions): self
    {
        return new self(sprintf(
            'The action [%s] resumed %d times without finishing, above the configured ceiling '.
            '(impex.limits.max_resumptions). Raise the limit or widen the work done per invocation.',
            $action,
            $resumptions,
        ));
    }
}
