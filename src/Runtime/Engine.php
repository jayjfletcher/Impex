<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JayI\Impex\Contracts\Resumable;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Enums\TimerKind;
use JayI\Impex\Events\RunCompleted;
use JayI\Impex\Events\RunFailed;
use JayI\Impex\Events\RunStarted;
use JayI\Impex\Events\StepCompleted;
use JayI\Impex\Events\StepFailed;
use JayI\Impex\Exceptions\CannotSignalTerminalRunException;
use JayI\Impex\Exceptions\HistoryMismatchException;
use JayI\Impex\Exceptions\StalledStepException;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Flows\Flow;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Jobs\DriveRun;
use JayI\Impex\Jobs\ExecuteStep;
use JayI\Impex\Jobs\SeedBatch;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Models\Signal;
use JayI\Impex\Models\Timer;
use JayI\Impex\Support\Locks;
use JayI\Impex\Support\PayloadStore;
use RuntimeException;
use Throwable;

/**
 * Drives runs forward.
 *
 * Two entry points do all the work. `drive()` replays the flow and schedules
 * whatever it reaches that has not been recorded; `executeStep()` claims one
 * step under lease, runs it, and records the outcome. Neither ever waits, so no
 * single invocation approaches the Lambda ceiling however long the run lives.
 */
final class Engine
{
    public function __construct(
        private readonly Container $container,
        private readonly FlowRegistry $flows,
        private readonly PayloadStore $payloads,
        private readonly Bus $bus,
        private readonly Locks $locks,
        private readonly Config $config,
        private readonly Events $events,
    ) {}

    public function payloads(): PayloadStore
    {
        return $this->payloads;
    }

    /**
     * Queue the first drive of a run.
     */
    public function start(Run $run): void
    {
        $this->dispatchDrive($run);
    }

    /**
     * Replay the run and schedule whatever comes next.
     */
    public function drive(string $runId): void
    {
        $lock = $this->locks->acquire($this->lockKey($runId), $this->lockSeconds());

        if (! $lock->get()) {
            // Another invocation holds the run. Leave a marker so the holder
            // re-reads the history before it lets go, rather than blocking a
            // Lambda invocation on a lock we may never win.
            $this->locks->store()->put($this->dirtyKey($runId), true, $this->lockSeconds());

            return;
        }

        try {
            do {
                $this->locks->store()->forget($this->dirtyKey($runId));

                $this->driveOnce($runId);
            } while ($this->locks->store()->pull($this->dirtyKey($runId)) === true);
        } finally {
            $lock->release();
        }
    }

    /**
     * Claim one step, run it, and record the outcome.
     */
    public function executeStep(string $runId, string $phase, int $sequence): void
    {
        $step = RunStep::query()
            ->where('run_id', $runId)
            ->where('phase', $phase)
            ->where('sequence', $sequence)
            ->first();

        if (! $step instanceof RunStep || ! $step->status->isClaimable()) {
            return;
        }

        $token = (string) Str::ulid();

        // Claim BEFORE executing. A unique (run_id, phase, sequence) alone would
        // not make redelivery safe, because a record-after-execute step calls
        // the upstream before it ever loses an insert race. Reclaiming a lapsed
        // lease is what stops a timed-out invocation wedging the run.
        $claimed = RunStep::query()
            ->whereKey($step->getKey())
            ->whereIn('status', [
                StepStatus::Pending->value,
                StepStatus::Running->value,
                StepStatus::Failed->value,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<=', Carbon::now());
            })
            ->update([
                'status' => StepStatus::Running->value,
                'lease_token' => $token,
                'leased_until' => Carbon::now()->addSeconds($this->leaseSeconds()),
                'started_at' => $step->started_at ?? Carbon::now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $step->refresh();

        try {
            $result = $this->invoke($step);
        } catch (Throwable $e) {
            $this->recordFailure($step, $token, $e);

            return;
        }

        if ($result instanceof Resume) {
            $this->recordResume($step, $token, $result);

            return;
        }

        $this->recordSuccess($step, $token, $result);
    }

    /**
     * Record an unrecorded operation and queue it.
     */
    public function scheduleStep(Run $run, int $sequence, StepDescriptor $descriptor): void
    {
        $step = $this->writeStep($run, StepPhase::Forward, $sequence, $descriptor);

        $this->dispatchStep($run, $step);
    }

    /**
     * Record a fan-out marker: the collection's fingerprint and key order.
     *
     * Recording this is what turns a divergent collection into an immediate,
     * named failure instead of a silent misalignment between items and results.
     *
     * @param  array<int, int|string>  $keys
     */
    public function recordFanOut(Run $run, int $sequence, string $fingerprint, array $keys): void
    {
        $stored = $this->payloads->put([
            'fingerprint' => $fingerprint,
            'keys' => $keys,
            'count' => count($keys),
        ], ArtifactKind::Result, ['run_id' => $run->getKey()]);

        RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => StepType::FanOut,
            'name' => 'fan-out',
            'status' => StepStatus::Completed,
            'result' => $stored['inline'],
            'result_artifact_id' => $stored['artifact_id'],
            'attempts' => 1,
            'max_attempts' => 1,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a batch as a single step and start seeding it.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function startBatch(
        Run $run,
        int $sequence,
        string $source,
        array $arguments,
        string $action,
        int $chunkSize,
        float $allowFailures,
        int $maxAttempts,
    ): void {
        $stored = $this->payloads->put($arguments, ArtifactKind::Payload, ['run_id' => $run->getKey()]);

        $step = RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => StepType::Batch,
            'name' => $source,
            'status' => StepStatus::Pending,
            'input' => $stored['inline'],
            'input_artifact_id' => $stored['artifact_id'],
            'max_attempts' => 1,
            'queued_at' => Carbon::now(),
        ]);

        $batch = Batch::query()->create([
            'run_id' => $run->getKey(),
            'step_id' => $step->getKey(),
            'source' => $source,
            'source_arguments' => $stored['inline'],
            'action' => $action,
            'chunk_size' => $chunkSize,
            'allow_failures' => $allowFailures,
            'max_attempts' => $maxAttempts,
        ]);

        $this->bus->dispatch($this->route(new SeedBatch((string) $batch->getKey()), $run));
    }

    /**
     * Record a value the replay cannot recompute.
     */
    public function recordSideEffect(Run $run, int $sequence, string $key, mixed $value): void
    {
        $stored = $this->payloads->put($value, ArtifactKind::Result, ['run_id' => $run->getKey()]);

        RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => StepType::SideEffect,
            'name' => $key,
            'status' => StepStatus::Completed,
            'result' => $stored['inline'],
            'result_artifact_id' => $stored['artifact_id'],
            'attempts' => 1,
            'max_attempts' => 1,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Record that the run is waiting on a signal, with an optional deadline.
     */
    public function recordSignalWait(Run $run, int $sequence, string $name, ?DateTimeInterface $timeout): void
    {
        $step = RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => StepType::Signal,
            'name' => $name,
            'status' => StepStatus::Pending,
            'max_attempts' => 1,
        ]);

        if ($timeout instanceof DateTimeInterface) {
            $this->writeTimer($run, $sequence, TimerKind::SignalTimeout, $timeout);
        }

        $this->events->dispatch(new StepCompleted($run->getKey(), $step->getKey()));
    }

    /**
     * Consume a signal already delivered to the run, if one is waiting.
     *
     * @return array{payload: mixed}|null
     */
    public function consumeSignal(Run $run, string $name, int $sequence, ?RunStep $step): ?array
    {
        $signal = Signal::query()
            ->where('run_id', $run->getKey())
            ->where('name', $name)
            ->whereNull('consumed_at')
            ->orderBy('delivered_at')
            ->first();

        if (! $signal instanceof Signal) {
            return null;
        }

        $payload = $this->payloads->get($signal->payload, $signal->payload_artifact_id);
        $stored = $this->payloads->put($payload, ArtifactKind::Result, ['run_id' => $run->getKey()]);

        if ($step instanceof RunStep) {
            $step->update([
                'status' => StepStatus::Completed,
                'result' => $stored['inline'],
                'result_artifact_id' => $stored['artifact_id'],
                'completed_at' => Carbon::now(),
            ]);
        } else {
            RunStep::query()->create([
                'run_id' => $run->getKey(),
                'phase' => StepPhase::Forward,
                'sequence' => $sequence,
                'type' => StepType::Signal,
                'name' => $name,
                'status' => StepStatus::Completed,
                'result' => $stored['inline'],
                'result_artifact_id' => $stored['artifact_id'],
                'attempts' => 1,
                'max_attempts' => 1,
                'completed_at' => Carbon::now(),
            ]);
        }

        $signal->update([
            'consumed_at' => Carbon::now(),
            'consumed_sequence' => $sequence,
        ]);

        return ['payload' => $payload];
    }

    /**
     * Record a wall-clock wait as a timer rather than a delayed job.
     */
    public function recordSleep(Run $run, int $sequence, DateTimeInterface $until): void
    {
        RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => StepType::Timer,
            'name' => 'sleep',
            'status' => StepStatus::Pending,
            'max_attempts' => 1,
        ]);

        $this->writeTimer($run, $sequence, TimerKind::Sleep, $until);
    }

    /**
     * Deliver a signal to a run from outside the flow.
     */
    public function deliverSignal(Run $run, string $name, mixed $payload = null, ?string $idempotencyKey = null): Signal
    {
        // A signal is accepted by any non-terminal run — pending, running, or
        // waiting. A finished one would hold the row forever unconsumed, so it
        // fails loudly instead.
        if ($run->status->isFinished()) {
            throw CannotSignalTerminalRunException::status((string) $run->getKey(), $run->status);
        }

        $stored = $this->payloads->put($payload, ArtifactKind::Payload, ['run_id' => $run->getKey()]);

        $signal = Signal::query()->firstOrCreate(
            [
                'run_id' => $run->getKey(),
                'name' => $name,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'payload' => $stored['inline'],
                'payload_artifact_id' => $stored['artifact_id'],
                'delivered_at' => Carbon::now(),
            ],
        );

        $this->dispatchDrive($run);

        return $signal;
    }

    /**
     * Fire a due timer: complete the step it was waiting on and drive the run.
     */
    public function fireTimer(Timer $timer): void
    {
        $step = RunStep::query()
            ->where('run_id', $timer->run_id)
            ->where('phase', StepPhase::Forward)
            ->where('sequence', $timer->sequence)
            ->first();

        if ($step instanceof RunStep && $step->status === StepStatus::Pending) {
            // Skipped, not Completed-with-null: the flow has to be able to tell
            // "the deadline passed" from "the signal arrived carrying null".
            $step->update([
                'status' => $step->type === StepType::Signal
                    ? StepStatus::Skipped
                    : StepStatus::Completed,
                'result' => ['value' => null],
                'completed_at' => Carbon::now(),
            ]);
        }

        $timer->update(['fired_at' => Carbon::now()]);

        $run = Run::query()->find($timer->run_id);

        if ($run instanceof Run) {
            $this->dispatchDrive($run);
        }
    }

    /**
     * Queue a drive for a run.
     */
    public function dispatchDrive(Run $run): void
    {
        $job = new DriveRun((string) $run->getKey());

        $this->bus->dispatch($this->route($job, $run));
    }

    private function driveOnce(string $runId): void
    {
        $run = Run::query()->find($runId);

        if (! $run instanceof Run || $run->status->isFinished()) {
            return;
        }

        if ($run->status === RunStatus::Compensating) {
            $this->compensateNext($run);

            return;
        }

        if ($run->status === RunStatus::Pending) {
            $run->update(['status' => RunStatus::Running, 'started_at' => Carbon::now()]);

            $this->events->dispatch(new RunStarted((string) $run->getKey()));
        }

        $context = new Context($run, $this->history($run), $this);

        try {
            $flow = $this->flows->make($run->flow_class)->withContext($context);

            /** @var array<int, mixed> $arguments */
            $arguments = (array) $this->payloads->get($run->input, $run->input_artifact_id);

            /** @phpstan-ignore-next-line handle() is declared by the concrete flow */
            $result = $flow->handle(...array_values($arguments));
        } catch (Suspended) {
            $this->applyTags($run, $context);

            $run->update([
                'status' => $context->isWaiting() ? RunStatus::Waiting : RunStatus::Running,
            ]);

            return;
        } catch (StepFailedException $e) {
            $this->applyTags($run, $context);

            $this->beginCompensation($run, $e);

            return;
        } catch (HistoryMismatchException $e) {
            // Never compensate a divergence: the recorded history no longer
            // describes what the code does, so a rollback would be guesswork.
            $this->failRun($run, $e);

            return;
        } catch (Throwable $e) {
            $this->applyTags($run, $context);

            $this->beginCompensation($run, $e);

            return;
        }

        $this->applyTags($run, $context);

        $this->completeRun($run, $result);
    }

    /**
     * @return array<int, RunStep>
     */
    private function history(Run $run): array
    {
        $history = [];

        foreach ($run->forwardSteps()->get() as $step) {
            $history[$step->sequence] = $step;
        }

        return $history;
    }

    private function applyTags(Run $run, Context $context): void
    {
        $tags = $context->tags();

        if ($tags === []) {
            return;
        }

        $run->update(['tags' => array_merge($run->tags ?? [], $tags)]);
    }

    private function completeRun(Run $run, mixed $result): void
    {
        $stored = $this->payloads->put($result, ArtifactKind::Result, ['run_id' => $run->getKey()]);

        $run->update([
            'status' => RunStatus::Completed,
            'result' => $stored['inline'],
            'result_artifact_id' => $stored['artifact_id'],
            'finished_at' => Carbon::now(),
        ]);

        $this->events->dispatch(new RunCompleted((string) $run->getKey()));
    }

    private function beginCompensation(Run $run, Throwable $error): void
    {
        $run->update([
            'status' => RunStatus::Compensating,
            'error' => $this->describe($error),
        ]);

        $this->compensateNext($run);
    }

    /**
     * Roll back the most recent compensatable step that has not been undone.
     */
    private function compensateNext(Run $run): void
    {
        $target = RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Forward)
            ->where('status', StepStatus::Completed)
            ->where('compensated', false)
            ->whereNotNull('compensation')
            ->orderByDesc('sequence')
            ->first();

        if (! $target instanceof RunStep) {
            $this->failRun($run, null);

            return;
        }

        /** @var array{action: string, arguments: array<int, mixed>} $compensation */
        $compensation = $target->compensation;

        $sequence = (int) RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Compensation)
            ->count();

        $step = $this->writeStep(
            $run,
            StepPhase::Compensation,
            $sequence,
            new StepDescriptor(
                type: StepType::Compensation,
                name: $compensation['action'],
                arguments: $compensation['arguments'],
            ),
            $target->sequence,
        );

        $this->dispatchStep($run, $step);
    }

    private function failRun(Run $run, ?Throwable $error): void
    {
        $run->update([
            'status' => RunStatus::Failed,
            'error' => $error instanceof Throwable ? $this->describe($error) : $run->error,
            'finished_at' => Carbon::now(),
        ]);

        $this->events->dispatch(new RunFailed((string) $run->getKey()));
    }

    private function writeStep(
        Run $run,
        StepPhase $phase,
        int $sequence,
        StepDescriptor $descriptor,
        ?int $compensates = null,
    ): RunStep {
        $stored = $this->payloads->put($descriptor->arguments, ArtifactKind::Payload, [
            'run_id' => $run->getKey(),
        ]);

        return RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => $phase,
            'sequence' => $sequence,
            'type' => $descriptor->type,
            'name' => $descriptor->name,
            'status' => StepStatus::Pending,
            'input' => $stored['inline'],
            'input_artifact_id' => $stored['artifact_id'],
            'compensation' => $descriptor->compensation,
            'max_attempts' => $descriptor->maxAttempts,
            'compensates_sequence' => $compensates,
            'expires_at' => $descriptor->expiresAt,
            'queued_at' => Carbon::now(),
        ]);
    }

    private function writeTimer(Run $run, int $sequence, TimerKind $kind, DateTimeInterface $wakeAt): void
    {
        Timer::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'kind' => $kind,
            'wake_at' => $wakeAt,
        ]);
    }

    private function invoke(RunStep $step): mixed
    {
        $action = $this->container->make($step->name);

        if (! is_object($action) || ! method_exists($action, 'execute')) {
            throw new RuntimeException(sprintf(
                'The Impex action [%s] must be a class declaring a public execute() method.',
                $step->name,
            ));
        }

        if ($action instanceof Resumable) {
            $cursor = $step->cursor;

            $action->withCheckpoint(
                is_array($cursor) && is_string($cursor['value'] ?? null) ? $cursor['value'] : null,
                StepDeadline::in($this->maxStepSeconds(), $this->resumeMargin()),
            );
        }

        /** @var array<int, mixed> $arguments */
        $arguments = (array) $this->payloads->get($step->input, $step->input_artifact_id);

        return $action->execute(...array_values($arguments));
    }

    /**
     * The action ran out of time before it ran out of work: checkpoint and
     * re-dispatch the same step rather than completing or failing it.
     */
    private function recordResume(RunStep $step, string $token, Resume $resume): void
    {
        $previous = is_array($step->cursor) && is_string($step->cursor['value'] ?? null)
            ? $step->cursor['value']
            : null;

        // A cursor that has not moved means the action cannot make progress.
        // Looping forever would burn the account's Lambda concurrency, so this
        // fails loudly instead.
        if ($step->resumptions > 0 && $resume->cursor === $previous) {
            $this->recordFailure($step, $token, StalledStepException::cursor($step->name, $resume->cursor));

            return;
        }

        if ($step->resumptions >= $this->maxResumptions()) {
            $this->recordFailure($step, $token, StalledStepException::resumptions($step->name, $step->resumptions));

            return;
        }

        $written = RunStep::query()
            ->whereKey($step->getKey())
            ->where('lease_token', $token)
            ->update([
                'status' => StepStatus::Pending->value,
                'cursor' => json_encode(['value' => $resume->cursor]),
                'resumptions' => DB::raw('resumptions + 1'),
                'lease_token' => null,
                'leased_until' => null,
                'updated_at' => Carbon::now(),
            ]);

        if ($written === 0) {
            return;
        }

        $run = Run::query()->find($step->run_id);

        if ($run instanceof Run) {
            $this->dispatchStep($run, $step, $resume->delaySeconds ?? 0);
        }
    }

    private function recordSuccess(RunStep $step, string $token, mixed $result): void
    {
        $stored = $this->payloads->put($result, ArtifactKind::Result, [
            'run_id' => $step->run_id,
            'step_id' => (string) $step->getKey(),
        ]);

        // Scoped to the lease token so a zombie that wakes after its lease
        // lapsed cannot clobber the winner's result.
        $written = RunStep::query()
            ->whereKey($step->getKey())
            ->where('lease_token', $token)
            ->update([
                'status' => StepStatus::Completed->value,
                'result' => json_encode($stored['inline']),
                'result_artifact_id' => $stored['artifact_id'],
                'completed_at' => Carbon::now(),
                'leased_until' => null,
                'updated_at' => Carbon::now(),
            ]);

        if ($written === 0) {
            return;
        }

        if ($step->phase === StepPhase::Compensation && $step->compensates_sequence !== null) {
            RunStep::query()
                ->where('run_id', $step->run_id)
                ->where('phase', StepPhase::Forward)
                ->where('sequence', $step->compensates_sequence)
                ->update(['compensated' => true]);
        }

        $this->events->dispatch(new StepCompleted((string) $step->run_id, (string) $step->getKey()));

        $run = Run::query()->find($step->run_id);

        if ($run instanceof Run) {
            $this->dispatchDrive($run);
        }
    }

    private function recordFailure(RunStep $step, string $token, Throwable $error): void
    {
        $run = Run::query()->find($step->run_id);

        if ($step->attempts < $step->max_attempts) {
            RunStep::query()
                ->whereKey($step->getKey())
                ->where('lease_token', $token)
                ->update([
                    'status' => StepStatus::Pending->value,
                    'lease_token' => null,
                    'leased_until' => null,
                    'error' => json_encode($this->describe($error)),
                    'updated_at' => Carbon::now(),
                ]);

            if ($run instanceof Run) {
                $this->dispatchStep($run, $step, $this->backoffSeconds($step->attempts));
            }

            return;
        }

        RunStep::query()
            ->whereKey($step->getKey())
            ->where('lease_token', $token)
            ->update([
                'status' => StepStatus::Failed->value,
                'lease_token' => null,
                'leased_until' => null,
                'error' => json_encode($this->describe($error)),
                'completed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        $this->events->dispatch(new StepFailed((string) $step->run_id, (string) $step->getKey()));

        if ($run instanceof Run) {
            $this->dispatchDrive($run);
        }
    }

    private function dispatchStep(Run $run, RunStep $step, int $delay = 0): void
    {
        $job = new ExecuteStep((string) $run->getKey(), $step->phase->value, $step->sequence);

        if ($delay > 0) {
            $job->delay($delay);
        }

        $this->bus->dispatch($this->route($job, $run));
    }

    /**
     * @template TJob of DriveRun|ExecuteStep|SeedBatch
     *
     * @param  TJob  $job
     * @return TJob
     */
    private function route(DriveRun|ExecuteStep|SeedBatch $job, Run $run): DriveRun|ExecuteStep|SeedBatch
    {
        /** @var string|null $connection */
        $connection = $run->queue_connection ?? $this->config->get('impex.queue.connection');

        /** @var string|null $queue */
        $queue = $run->queue ?? $this->config->get('impex.queue.queue');

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Throwable $error): array
    {
        return [
            'class' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }

    private function backoffSeconds(int $attempts): int
    {
        return min(60 * $attempts, 900);
    }

    private function lockKey(string $runId): string
    {
        return 'impex:run:'.$runId;
    }

    private function dirtyKey(string $runId): string
    {
        return 'impex:run:'.$runId.':dirty';
    }

    private function lockSeconds(): int
    {
        /** @var int $seconds */
        $seconds = $this->config->get('impex.limits.lock_seconds', 120);

        return $seconds;
    }

    private function maxStepSeconds(): int
    {
        /** @var int $seconds */
        $seconds = $this->config->get('impex.limits.max_step_seconds', 840);

        return $seconds;
    }

    private function resumeMargin(): int
    {
        /** @var int $seconds */
        $seconds = $this->config->get('impex.limits.resume_margin_seconds', 30);

        return $seconds;
    }

    private function maxResumptions(): int
    {
        /** @var int $max */
        $max = $this->config->get('impex.limits.max_resumptions', 10000);

        return $max;
    }

    private function leaseSeconds(): int
    {
        /** @var int $seconds */
        $seconds = $this->config->get('impex.limits.lease_seconds', 900);

        return $seconds;
    }
}
