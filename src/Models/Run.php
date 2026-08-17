<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use JayI\Impex\Database\Factories\RunFactory;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepPhase;

/**
 * @property string $id
 * @property string $flow
 * @property string $flow_class
 * @property string|null $flow_version
 * @property RunStatus $status
 * @property RunTrigger $trigger
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $input
 * @property string|null $input_artifact_id
 * @property array<string, mixed>|null $result
 * @property string|null $result_artifact_id
 * @property array<string, mixed>|null $error
 * @property array<string, string>|null $tags
 * @property string|null $parent_run_id
 * @property int|null $parent_sequence
 * @property string|null $queue_connection
 * @property string|null $queue
 * @property Carbon|null $expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    use HasUlids;
    use Prunable;

    protected $table = 'impex_runs';

    protected $fillable = [
        'flow',
        'flow_class',
        'flow_version',
        'status',
        'trigger',
        'idempotency_key',
        'input',
        'input_artifact_id',
        'result',
        'result_artifact_id',
        'error',
        'tags',
        'parent_run_id',
        'parent_sequence',
        'queue_connection',
        'queue',
        'expires_at',
        'started_at',
        'finished_at',
    ];

    /**
     * @return HasMany<RunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class, 'run_id');
    }

    /**
     * The forward history, in replay order.
     *
     * @return HasMany<RunStep, $this>
     */
    public function forwardSteps(): HasMany
    {
        return $this->steps()
            ->where('phase', StepPhase::Forward)
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<RunOwner, $this>
     */
    public function owners(): HasMany
    {
        return $this->hasMany(RunOwner::class, 'run_id');
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'run_id');
    }

    /**
     * @return HasMany<Timer, $this>
     */
    public function timers(): HasMany
    {
        return $this->hasMany(Timer::class, 'run_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'run_id');
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Run::class, 'parent_run_id');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'parent_run_id');
    }

    /**
     * Scope to runs owned by the given model, in any role.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWhereOwnedBy(Builder $query, Model $owner, ?string $role = null): void
    {
        $query->whereHas('owners', function (Builder $owners) use ($owner, $role): void {
            $owners->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', (string) $owner->getKey());

            if ($role !== null) {
                $owners->where('role', $role);
            }
        });
    }

    /**
     * Scope to runs owned by any of the given models.
     *
     * @param  Builder<$this>  $query
     * @param  iterable<int, Model>  $owners
     */
    public function scopeWhereOwnedByAny(Builder $query, iterable $owners): void
    {
        $pairs = [];

        foreach ($owners as $owner) {
            $pairs[] = [$owner->getMorphClass(), (string) $owner->getKey()];
        }

        if ($pairs === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('owners', function (Builder $query) use ($pairs): void {
            $query->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as [$type, $id]) {
                    $query->orWhere(function (Builder $query) use ($type, $id): void {
                        $query->where('owner_type', $type)->where('owner_id', $id);
                    });
                }
            });
        });
    }

    /**
     * Runs that may still be signalled.
     *
     * An alias of `active()`, and the scope to reach for when looking for a run
     * to signal: filtering by `running()` would miss exactly the runs that are
     * parked waiting for one.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSignalable(Builder $query): void
    {
        $this->scopeActive($query);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            RunStatus::Pending,
            RunStatus::Running,
            RunStatus::Waiting,
            RunStatus::Compensating,
        ]);
    }

    /**
     * @return Builder<Run>
     */
    public function prunable(): Builder
    {
        /** @var int $completed */
        $completed = config('impex.retention.completed_runs_days', 90);

        /** @var int $failed */
        $failed = config('impex.retention.failed_runs_days', 365);

        return $this->newQuery()
            ->where(function (Builder $query) use ($completed, $failed): void {
                $query->where(function (Builder $query) use ($completed): void {
                    $query->whereIn('status', [RunStatus::Completed, RunStatus::Cancelled])
                        ->where('finished_at', '<=', Carbon::now()->subDays($completed));
                })->orWhere(function (Builder $query) use ($failed): void {
                    $query->where('status', RunStatus::Failed)
                        ->where('finished_at', '<=', Carbon::now()->subDays($failed));
                });
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'trigger' => RunTrigger::class,
            'input' => 'array',
            'result' => 'array',
            'error' => 'array',
            'tags' => 'array',
            'parent_sequence' => 'integer',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RunFactory
    {
        return RunFactory::new();
    }
}
