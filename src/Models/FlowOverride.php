<?php

declare(strict_types=1);

namespace JayI\Impex\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JayI\Impex\Models\Concerns\DispatchesModelEvents;

/**
 * A runtime override for a registered flow.
 *
 * The registry decides which flows exist; this decides whether one is currently
 * enabled and how it is scheduled. A row whose slug is not registered is inert.
 *
 * @property string $id
 * @property string $slug
 * @property bool|null $enabled
 * @property string|null $schedule
 * @property string|null $queue
 * @property string|null $queue_connection
 * @property array<string, mixed>|null $defaults
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FlowOverride extends Model
{
    use DispatchesModelEvents;
    use HasUlids;

    protected $table = 'impex_flows';

    protected $fillable = [
        'slug',
        'enabled',
        'schedule',
        'queue',
        'queue_connection',
        'defaults',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'defaults' => 'array',
        ];
    }
}
