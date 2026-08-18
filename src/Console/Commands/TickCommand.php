<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Models\Timer;
use JayI\Impex\Runtime\BatchRunner;
use JayI\Impex\Runtime\Engine;

/**
 * Sweeps due timers and reclaims lapsed step leases.
 *
 * This command is what makes waits longer than the queue's delay ceiling
 * possible: SQS caps message delay at 15 minutes, so a run that sleeps for a
 * day is a row in `impex_timers` rather than a delayed job. Without this on the
 * schedule, such a run never wakes.
 *
 * It is also the engine's repair pass — a step whose invocation was killed
 * mid-flight has its lease reclaimed here and is re-dispatched from its last
 * checkpoint.
 */
final class TickCommand extends Command
{
    protected $signature = 'impex:tick {--limit= : Maximum timers to fire in this sweep}';

    protected $description = 'Fire due Impex timers and reclaim lapsed step leases';

    public function handle(Engine $engine, BatchRunner $batches, Config $config): int
    {
        /** @var int $limit */
        $limit = $this->option('limit') ?? $config->get('impex.timers.batch', 250);

        $fired = $this->fireTimers($engine, $config, (int) $limit);
        $reclaimed = $this->reclaimLeases($engine, $config);
        // Backstop for a completion check that lost its throttle lock: without
        // this a finished batch could sit unfinalised until another item moved.
        $finalized = $batches->sweep();
        // Deadlines are enforced here rather than in-process: a step that has
        // handed control to an upstream call cannot check a clock.
        $expired = $engine->enforceDeadlines();

        $this->components->info(sprintf(
            'Impex tick complete: %d timer(s) fired, %d step(s) reclaimed, %d batch(es) finalized, %d deadline(s) enforced.',
            $fired,
            $reclaimed,
            $finalized,
            $expired,
        ));

        return self::SUCCESS;
    }

    private function fireTimers(Engine $engine, Config $config, int $limit): int
    {
        /** @var int $claimSeconds */
        $claimSeconds = $config->get('impex.timers.claim_seconds', 300);

        $token = (string) Str::ulid();

        // The lease clause is what stops a timer stranding forever: a bare
        // `claimed_at IS NULL` predicate never matches again if the claimer
        // dies between claiming and dispatching.
        $ids = Timer::query()
            ->whereNull('fired_at')
            ->where('wake_at', '<=', Carbon::now())
            ->where(function (Builder $query) use ($claimSeconds): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', Carbon::now()->subSeconds($claimSeconds));
            })
            ->orderBy('wake_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        Timer::query()->whereIn('id', $ids)->update([
            'claimed_at' => Carbon::now(),
            'claim_token' => $token,
        ]);

        $claimed = Timer::query()->where('claim_token', $token)->get();

        foreach ($claimed as $timer) {
            $engine->fireTimer($timer);
        }

        return $claimed->count();
    }

    private function reclaimLeases(Engine $engine, Config $config): int
    {
        $steps = RunStep::query()
            ->where('status', StepStatus::Running)
            ->whereNotNull('leased_until')
            ->where('leased_until', '<=', Carbon::now())
            ->limit(250)
            ->get();

        foreach ($steps as $step) {
            // Release the lease rather than failing the step: it may have been
            // a killed invocation, and a resumable step still holds its cursor.
            RunStep::query()->whereKey($step->getKey())->update([
                'status' => StepStatus::Pending->value,
                'lease_token' => null,
                'leased_until' => null,
            ]);

            $run = Run::query()->find($step->run_id);

            if ($run instanceof Run && $run->status->isActive()) {
                $engine->dispatchDrive($run);
            }
        }

        return $steps->count();
    }
}
