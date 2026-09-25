<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JayI\Impex\Database\Factories\MessageFactory;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Models\Concerns\DispatchesModelEvents;

/**
 * @property string $id
 * @property string|null $run_id
 * @property string|null $step_id
 * @property Direction $direction
 * @property string $channel
 * @property string $transport
 * @property string $endpoint
 * @property string|null $method
 * @property int|null $status_code
 * @property array<string, mixed>|null $headers
 * @property string|null $body_artifact_id
 * @property string|null $body_preview
 * @property int $bytes
 * @property bool|null $signature_valid
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $error
 * @property string|null $idempotency_key
 * @property Carbon $occurred_at
 */
final class Message extends Model
{
    use DispatchesModelEvents;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasUlids;

    // Safe to mass prune: a message owns no external resource. Its body lives
    // on an Artifact, which prunes itself and deletes its own object.
    use MassPrunable;

    protected $table = 'impex_messages';

    protected $fillable = [
        'run_id',
        'step_id',
        'direction',
        'channel',
        'transport',
        'endpoint',
        'method',
        'status_code',
        'headers',
        'body_artifact_id',
        'body_preview',
        'bytes',
        'signature_valid',
        'duration_ms',
        'error',
        'idempotency_key',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * @return BelongsTo<Artifact, $this>
     */
    public function body(): BelongsTo
    {
        return $this->belongsTo(Artifact::class, 'body_artifact_id');
    }

    /**
     * @return Builder<Message>
     */
    public function prunable(): Builder
    {
        /** @var int $days */
        $days = config('impex.retention.messages_days', 90);

        return $this->newQuery()->where('occurred_at', '<=', Carbon::now()->subDays($days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'headers' => 'array',
            'error' => 'array',
            'bytes' => 'integer',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'signature_valid' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}
