<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property string $step_id
 * @property string $source
 * @property array<string, mixed>|null $source_arguments
 * @property string $action
 * @property int $chunk_size
 * @property float $allow_failures
 * @property int $max_attempts
 * @property bool $seeded
 * @property int $total
 * @property int $succeeded
 * @property int $failed
 * @property Carbon|null $finalized_at
 */
final class Batch extends Model
{
    use HasUlids;

    protected $table = 'impex_batches';

    protected $fillable = [
        'run_id',
        'step_id',
        'source',
        'source_arguments',
        'action',
        'chunk_size',
        'allow_failures',
        'max_attempts',
        'seeded',
        'total',
        'succeeded',
        'failed',
        'finalized_at',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * @return HasMany<BatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BatchItem::class, 'batch_id');
    }

    /**
     * Whether the failure count is still inside the tolerated share.
     */
    public function withinFailureThreshold(): bool
    {
        if ($this->total === 0) {
            return true;
        }

        return $this->failed / $this->total <= $this->allow_failures;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_arguments' => 'array',
            'chunk_size' => 'integer',
            'allow_failures' => 'float',
            'max_attempts' => 'integer',
            'seeded' => 'boolean',
            'total' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }
}
