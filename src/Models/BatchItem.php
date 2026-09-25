<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Models\Concerns\DispatchesModelEvents;

/**
 * @property string $id
 * @property string $batch_id
 * @property string $item_key
 * @property array<string, mixed>|null $payload
 * @property string|null $payload_artifact_id
 * @property StepStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $result_artifact_id
 * @property array<string, mixed>|null $error
 * @property int $attempts
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 */
final class BatchItem extends Model
{
    use DispatchesModelEvents;
    use HasUlids;

    protected $table = 'impex_batch_items';

    protected $fillable = [
        'batch_id',
        'item_key',
        'payload',
        'payload_artifact_id',
        'status',
        'result',
        'result_artifact_id',
        'error',
        'attempts',
        'lease_token',
        'leased_until',
    ];

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'error' => 'array',
            'attempts' => 'integer',
            'leased_until' => 'datetime',
        ];
    }
}
