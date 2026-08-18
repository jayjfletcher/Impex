<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JayI\Impex\Database\Factories\RunStepFactory;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;

/**
 * @property string $id
 * @property string $run_id
 * @property StepPhase $phase
 * @property int $sequence
 * @property StepType $type
 * @property string $name
 * @property StepStatus $status
 * @property array<string, mixed>|null $input
 * @property string|null $input_artifact_id
 * @property array<string, mixed>|null $result
 * @property string|null $result_artifact_id
 * @property array<string, mixed>|null $error
 * @property array<string, mixed>|null $compensation
 * @property bool $compensated
 * @property int $attempts
 * @property int $max_attempts
 * @property array<string, mixed>|null $cursor
 * @property int $resumptions
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 * @property int|null $compensates_sequence
 * @property string|null $saga_group
 * @property Carbon|null $expires_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
final class RunStep extends Model
{
    /** @use HasFactory<RunStepFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'impex_run_steps';

    protected $fillable = [
        'run_id',
        'phase',
        'sequence',
        'type',
        'name',
        'status',
        'input',
        'input_artifact_id',
        'result',
        'result_artifact_id',
        'error',
        'compensation',
        'compensated',
        'attempts',
        'max_attempts',
        'cursor',
        'resumptions',
        'lease_token',
        'leased_until',
        'compensates_sequence',
        'saga_group',
        'expires_at',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * Whether this step's lease has lapsed and may be reclaimed.
     */
    public function leaseHasLapsed(): bool
    {
        return $this->leased_until === null || $this->leased_until->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => StepPhase::class,
            'type' => StepType::class,
            'status' => StepStatus::class,
            'sequence' => 'integer',
            'input' => 'array',
            'result' => 'array',
            'error' => 'array',
            'compensation' => 'array',
            'compensated' => 'boolean',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'cursor' => 'array',
            'resumptions' => 'integer',
            'compensates_sequence' => 'integer',
            'leased_until' => 'datetime',
            'expires_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RunStepFactory
    {
        return RunStepFactory::new();
    }
}
