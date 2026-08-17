<?php

declare(strict_types=1);

namespace JayI\Impex\Exceptions;

/**
 * Thrown when a replay diverges from the history already recorded for a run.
 *
 * The usual causes are non-deterministic code in handle() that was not wrapped
 * in sideEffect(), or a code change deployed underneath a live run.
 */
final class HistoryMismatchException extends ImpexException
{
    public static function at(int $sequence, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Replay diverged at sequence %d: history recorded [%s] but the flow asked for [%s]. '.
            'Wrap non-deterministic reads in sideEffect(), and do not change a flow while its runs are live.',
            $sequence,
            $expected,
            $actual,
        ));
    }

    public static function fanOut(int $sequence, int $recorded, int $actual): self
    {
        return new self(sprintf(
            'The fan-out at sequence %d walked a different collection than the one recorded '.
            '(%d item(s) then, %d now). Fan out over the result of a recorded step rather than a fresh '.
            'read, or call keyBy() so items are resolved by key instead of by position.',
            $sequence,
            $recorded,
            $actual,
        ));
    }

    public static function fanOutItem(int $sequence, string $key): self
    {
        return new self(sprintf(
            'The fan-out at sequence %d recorded a step for item [%s], but that item is no longer in the '.
            'collection. Items may be re-ordered between drives, but not removed.',
            $sequence,
            $key,
        ));
    }
}
