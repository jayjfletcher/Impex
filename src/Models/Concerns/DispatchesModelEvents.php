<?php

declare(strict_types=1);

namespace JayI\Impex\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Dispatch a class-based event for every Eloquent lifecycle hook.
 *
 * Each hook maps by convention to `JayI\Impex\Events\Model\{Model}{Hook}Event`
 * — `RunStep` fires `RunStepCreatingEvent` — and hooks without such a class
 * are skipped. Entries a model declares on `$dispatchesEvents` itself win
 * over the derived ones.
 */
trait DispatchesModelEvents
{
    /**
     * Every Eloquent hook, in the order Eloquent documents them.
     *
     * @var list<string>
     */
    private static array $modelEventHooks = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored',
        'trashed', 'forceDeleting', 'forceDeleted', 'replicating',
    ];

    /**
     * @var array<class-string, array<string, class-string>>
     */
    private static array $derivedModelEvents = [];

    protected function initializeDispatchesModelEvents(): void
    {
        // At construction $dispatchesEvents holds only the class's declared
        // entries, so the merged map is the same for every instance.
        $this->dispatchesEvents = self::$derivedModelEvents[static::class]
            ??= array_merge(self::deriveModelEvents(), $this->dispatchesEvents);
    }

    /**
     * @return array<string, class-string>
     */
    private static function deriveModelEvents(): array
    {
        $prefix = class_basename(static::class);
        $map = [];

        foreach (self::$modelEventHooks as $hook) {
            $event = 'JayI\\Impex\\Events\\Model\\'.$prefix.Str::ucfirst($hook).'Event';

            if (class_exists($event)) {
                $map[$hook] = $event;
            }
        }

        return $map;
    }
}
