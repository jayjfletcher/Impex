<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\TimerKind;
use JayI\Impex\Models\Concerns\DispatchesModelEvents;

/**
 * @property string $id
 * @property string $run_id
 * @property StepPhase|null $phase
 * @property int|null $sequence
 * @property TimerKind $kind
 * @property Carbon $wake_at
 * @property Carbon|null $claimed_at
 * @property string|null $claim_token
 * @property Carbon|null $fired_at
 */
final class Timer extends Model
{
    use DispatchesModelEvents;
    use HasUlids;

    protected $table = 'impex_timers';

    protected $fillable = [
        'run_id',
        'phase',
        'sequence',
        'kind',
        'wake_at',
        'claimed_at',
        'claim_token',
        'fired_at',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => StepPhase::class,
            'kind' => TimerKind::class,
            'sequence' => 'integer',
            'wake_at' => 'datetime',
            'claimed_at' => 'datetime',
            'fired_at' => 'datetime',
        ];
    }
}
