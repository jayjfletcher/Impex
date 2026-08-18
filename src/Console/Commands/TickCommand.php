<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use JayI\Impex\Runtime\Sweeper;

/**
 * Runs the engine's periodic pass.
 *
 * This command is what makes waits longer than the queue's delay ceiling
 * possible: SQS caps message delay at 15 minutes, so a run that sleeps for a
 * day is a row in `impex_timers` rather than a delayed job. Without this on the
 * schedule, such a run never wakes.
 *
 * It is also the repair pass — a step whose invocation was killed mid-flight
 * has its lease reclaimed here and is re-dispatched from its last checkpoint —
 * and the only place deadlines are enforced.
 */
final class TickCommand extends Command
{
    protected $signature = 'impex:tick {--limit= : Maximum timers to fire in this sweep}';

    protected $description = 'Fire due Impex timers, reclaim lapsed leases, and enforce deadlines';

    public function handle(Sweeper $sweeper): int
    {
        /** @var string|null $limit */
        $limit = $this->option('limit');

        $report = $sweeper->sweep($limit === null ? null : (int) $limit);

        $this->components->info('Impex tick: '.$report->summary());

        return self::SUCCESS;
    }
}
