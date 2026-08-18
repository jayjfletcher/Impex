<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * Signals to the engine that a recorded step failed terminally, so the run
 * should begin rolling back. Never thrown out of the engine to user code.
 */
final class StepFailedException extends ImpexException
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $step,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(int $sequence, string $step, ?string $message): self
    {
        return new self($sequence, $step, sprintf(
            'Step [%s] at sequence %d failed: %s',
            $step,
            $sequence,
            $message ?? 'no message recorded',
        ));
    }
}
