<?php

declare(strict_types=1);

namespace JayI\Impex;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Exceptions\CannotSignalTerminalRunException;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Models\BatchItem;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Runtime\RunHandle;
use JayI\Impex\Runtime\RunQuery;
use JayI\Impex\Support\MessageRecorder;
use JayI\Impex\Support\OutboundRecorder;
use JayI\Impex\Support\PayloadStore;

/**
 * The package's public entry point.
 */
class Impex
{
    public function __construct(
        private readonly FlowRegistry $flows,
        private readonly Engine $engine,
        private readonly PayloadStore $payloads,
        private readonly MessageRecorder $messages,
        private readonly OutboundRecorder $outbound,
    ) {}

    /**
     * The flow catalogue.
     */
    public function flows(): FlowRegistry
    {
        return $this->flows;
    }

    /**
     * Start a run and queue its first drive.
     *
     * Returns immediately with a pending run — nothing is executed inline, so
     * a trigger endpoint can respond inside API Gateway's timeout however long
     * the flow takes to finish.
     *
     * @param  array<int, mixed>  $arguments
     * @param  array<string, string>  $tags
     * @param  iterable<int|string, Model>  $owners  models keyed by role
     */
    public function run(
        string $slug,
        array $arguments = [],
        RunTrigger $trigger = RunTrigger::Code,
        ?string $idempotencyKey = null,
        array $tags = [],
        iterable $owners = [],
        ?string $version = null,
        DateTimeInterface|int|null $expiresAt = null,
    ): Run {
        if ($idempotencyKey !== null) {
            $existing = Run::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof Run) {
                return $existing;
            }
        }

        $class = $this->flows->class($slug);
        $stored = $this->payloads->put(array_values($arguments), ArtifactKind::Payload);

        $run = Run::query()->create([
            'flow' => $slug,
            'flow_class' => $class,
            'status' => RunStatus::Pending,
            'trigger' => $trigger,
            'idempotency_key' => $idempotencyKey,
            'flow_version' => $version ?? $this->flows->version($slug),
            'input' => $stored['inline'],
            'input_artifact_id' => $stored['artifact_id'],
            'tags' => $tags === [] ? null : $tags,
            'expires_at' => $this->deadline($expiresAt),
        ]);

        foreach ($owners as $role => $owner) {
            $this->addOwner($run, $owner, is_string($role) ? $role : 'owner');
        }

        $this->engine->start($run);

        return $run;
    }

    /**
     * Start a run and drive it to completion in this process.
     *
     * For tests, and for short flows behind a request. Steps execute inline
     * rather than being queued, so nothing here needs a worker. A flow that
     * parks on a signal or a timer will not finish and is returned as it
     * stands; the budget is `impex.limits.sync_seconds`.
     *
     * @param  array<int, mixed>  $arguments
     * @param  array<string, string>  $tags
     * @param  iterable<int|string, Model>  $owners
     */
    public function runSync(
        string $slug,
        array $arguments = [],
        RunTrigger $trigger = RunTrigger::Code,
        ?string $idempotencyKey = null,
        array $tags = [],
        iterable $owners = [],
        ?string $version = null,
        ?int $seconds = null,
    ): Run {
        $run = $this->run($slug, $arguments, $trigger, $idempotencyKey, $tags, $owners, $version);

        /** @var int $budget */
        $budget = $seconds ?? config('impex.limits.sync_seconds', 15);

        return $this->engine->driveToCompletion($run, $budget);
    }

    /**
     * Ask for runs.
     */
    public function query(): RunQuery
    {
        return new RunQuery;
    }

    /**
     * A run you can act on directly.
     */
    public function handle(Run|string $run): RunHandle
    {
        return new RunHandle($run instanceof Run ? $run : Run::query()->findOrFail($run));
    }

    /**
     * Give a model a stake in a run.
     *
     * No user, team or customer tables ship with this package: the host app
     * decides what those are, and the hierarchy between them lives there.
     */
    public function addOwner(Run $run, Model $owner, string $role = 'owner'): void
    {
        $run->owners()->firstOrCreate([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'role' => $role,
        ]);
    }

    /**
     * Deliver a signal to a run.
     *
     * Accepted by any non-terminal run — pending, running, or waiting. A signal
     * that arrives before the flow reaches its wait is held, not lost.
     *
     * @throws CannotSignalTerminalRunException if the run has finished
     */
    public function signal(Run $run, string $name, mixed $payload = null, ?string $idempotencyKey = null): Signal
    {
        return $this->engine->deliverSignal($run, $name, $payload, $idempotencyKey);
    }

    /**
     * Deliver a signal, treating a finished run as a no-op.
     *
     * Use this when losing a race with the run's own completion is expected
     * rather than exceptional.
     */
    public function signalIfRunning(Run $run, string $name, mixed $payload = null, ?string $idempotencyKey = null): bool
    {
        if ($run->status->isFinished()) {
            return false;
        }

        try {
            $this->engine->deliverSignal($run, $name, $payload, $idempotencyKey);
        } catch (CannotSignalTerminalRunException) {
            // The run finished between the check and the write.
            return false;
        }

        return true;
    }

    /**
     * Cancel a run that has not finished.
     */
    public function cancel(Run $run, ?string $reason = null): Run
    {
        if ($run->status->isFinished()) {
            return $run;
        }

        $run->update([
            'status' => RunStatus::Cancelled,
            'error' => $reason === null ? $run->error : ['message' => $reason],
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * Re-queue a drive for a run that stalled.
     */
    public function retry(Run $run): Run
    {
        if ($run->status === RunStatus::Failed) {
            $run->update(['status' => RunStatus::Running, 'finished_at' => null]);
        }

        $this->engine->dispatchDrive($run);

        return $run->refresh();
    }

    /**
     * An HTTP client whose traffic is recorded against a channel.
     *
     * Pass the run and step so each call is traceable to the work that made it.
     */
    public function http(string $channel, ?string $runId = null, ?string $stepId = null): PendingRequest
    {
        return $this->outbound->client($channel, $runId, $stepId);
    }

    /**
     * Record egress the HTTP middleware cannot see — a file drop, an SFTP put,
     * a message published to another system.
     *
     * @param  array<string, mixed>|null  $headers
     */
    public function record(
        string $channel,
        string $endpoint,
        ?string $body = null,
        string $transport = 'file',
        Direction $direction = Direction::Outbound,
        ?string $runId = null,
        ?array $headers = null,
    ): Message {
        return $this->messages->record(
            direction: $direction,
            channel: $channel,
            transport: $transport,
            endpoint: $endpoint,
            body: $body,
            runId: $runId,
            headers: $headers,
        );
    }

    /**
     * Read a recorded message body back from the ledger.
     */
    public function body(Message $message): ?string
    {
        return $this->messages->body($message);
    }

    /**
     * Stream a finished batch's items without holding them in memory.
     *
     * @return LazyCollection<int, BatchItem>
     */
    public function batchItems(string $batchId): LazyCollection
    {
        return BatchItem::query()
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->lazyById(1000);
    }

    /**
     * Read a run's result, from the inline column or the artifact disk.
     */
    public function result(Run $run): mixed
    {
        return $this->payloads->get($run->result, $run->result_artifact_id);
    }

    /**
     * Normalise a deadline given as a moment or a number of seconds.
     */
    private function deadline(DateTimeInterface|int|null $expiresAt): ?DateTimeInterface
    {
        if ($expiresAt === null) {
            /** @var int|null $default */
            $default = config('impex.deadlines.run');

            return $default === null ? null : Carbon::now()->addSeconds($default);
        }

        return is_int($expiresAt) ? Carbon::now()->addSeconds($expiresAt) : $expiresAt;
    }
}
