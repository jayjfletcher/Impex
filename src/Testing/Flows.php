<?php

declare(strict_types=1);

namespace JayI\Impex\Testing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Engine;
use PHPUnit\Framework\Assert;

/**
 * Test helpers for applications that build on Impex.
 *
 * Everything here is reachable through the public API; these exist so a test
 * reads as the behaviour it is checking, and so the failure messages say what
 * went wrong rather than "false is not true".
 *
 *   use JayI\Impex\Testing\Flows;
 *
 *   $run = Flows::run('extract-products', ['drill bits']);
 *
 *   Flows::assertCompleted($run);
 *   Flows::assertStepRan($run, FetchPricing::class, times: 1);
 *   Flows::assertRolledBack($run, RollbackPimWrite::class);
 *
 * A class rather than a trait so it works from Pest's functional style as
 * readily as from a TestCase.
 */
final class Flows
{
    /**
     * Start a run and drive it to completion in-process.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function run(string $slug, array $arguments = [], ?int $seconds = null): Run
    {
        return app(Impex::class)->runSync($slug, $arguments, seconds: $seconds);
    }

    /**
     * Move the clock forward and let the sweep act on it.
     *
     * The way to test a signal timeout, a sleep, or a deadline without waiting.
     */
    public static function travelTo(string|Carbon $moment): void
    {
        Carbon::setTestNow($moment instanceof Carbon ? $moment : Carbon::parse($moment));

        Artisan::call('impex:tick');

        Carbon::setTestNow();
    }

    public static function assertCompleted(Run $run): Run
    {
        $run = $run->refresh();

        Assert::assertSame(
            RunStatus::Completed,
            $run->status,
            sprintf(
                'Expected the run of [%s] to have completed, but it is %s.%s',
                $run->flow,
                $run->status->value,
                is_array($run->error) && is_string($run->error['message'] ?? null)
                    ? ' Error: '.$run->error['message']
                    : '',
            ),
        );

        return $run;
    }

    public static function assertFailed(Run $run, ?string $messageContains = null): Run
    {
        $run = $run->refresh();

        Assert::assertSame(
            RunStatus::Failed,
            $run->status,
            sprintf('Expected the run of [%s] to have failed, but it is %s.', $run->flow, $run->status->value),
        );

        if ($messageContains !== null) {
            $message = is_array($run->error) && is_string($run->error['message'] ?? null)
                ? $run->error['message']
                : '';

            Assert::assertStringContainsString($messageContains, $message);
        }

        return $run;
    }

    public static function assertWaiting(Run $run): Run
    {
        $run = $run->refresh();

        Assert::assertSame(
            RunStatus::Waiting,
            $run->status,
            sprintf('Expected the run of [%s] to be waiting, but it is %s.', $run->flow, $run->status->value),
        );

        return $run;
    }

    /**
     * Assert an action ran, optionally a given number of times.
     */
    public static function assertStepRan(Run $run, string $action, ?int $times = null): void
    {
        $steps = $run->steps()->where('name', $action)->get();

        if ($times === null) {
            Assert::assertTrue(
                $steps->isNotEmpty(),
                sprintf('Expected [%s] to have run, but no step recorded it.', $action),
            );

            return;
        }

        Assert::assertCount(
            $times,
            $steps,
            sprintf('Expected [%s] to have run %d time(s).', $action, $times),
        );
    }

    public static function assertStepDidNotRun(Run $run, string $action): void
    {
        Assert::assertFalse(
            $run->steps()->where('name', $action)->exists(),
            sprintf('Expected [%s] not to have run, but a step recorded it.', $action),
        );
    }

    /**
     * Assert a rollback ran for the run.
     */
    public static function assertRolledBack(Run $run, ?string $action = null): void
    {
        $query = $run->steps()
            ->where('phase', StepPhase::Rollback)
            ->where('status', StepStatus::Completed);

        if ($action !== null) {
            $query->where('name', $action);
        }

        Assert::assertTrue(
            $query->exists(),
            $action === null
                ? 'Expected the run to have undone, but no rollback step completed.'
                : sprintf('Expected [%s] to have rolled back the run.', $action),
        );
    }

    public static function assertNotRolledBack(Run $run): void
    {
        Assert::assertFalse(
            $run->steps()->where('phase', StepPhase::Rollback)->exists(),
            'Expected the run not to have undone, but a rollback step was recorded.',
        );
    }

    /**
     * Assert how many steps the run recorded going forward.
     *
     * Useful for pinning the cost of a flow: a batch should stay at one step
     * however many items it processes.
     */
    public static function assertForwardStepCount(Run $run, int $expected): void
    {
        Assert::assertSame(
            $expected,
            $run->forwardSteps()->count(),
            sprintf('Expected the run of [%s] to have recorded %d forward step(s).', $run->flow, $expected),
        );
    }

    /**
     * Assert a run is parked on a named signal.
     */
    public static function assertAwaitingSignal(Run $run, string $name): void
    {
        self::assertWaiting($run);

        Assert::assertTrue(
            $run->forwardSteps()
                ->where('name', $name)
                ->where('status', StepStatus::Pending)
                ->exists(),
            sprintf('Expected the run to be awaiting the signal [%s].', $name),
        );
    }

    /**
     * Redeliver every recorded step's job, asserting the run is unchanged.
     *
     * This is the test that matters most for a queue with at-least-once
     * delivery: side effects must not repeat.
     */
    public static function redeliverSteps(Run $run): void
    {
        $engine = app(Engine::class);

        foreach ($run->steps()->get() as $step) {
            /** @var RunStep $step */
            $engine->executeStep((string) $run->getKey(), $step->phase->value, $step->sequence);
        }
    }
}
