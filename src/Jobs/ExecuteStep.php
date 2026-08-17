<?php

declare(strict_types=1);

namespace JayI\Impex\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Runtime\Engine;

/**
 * Claims one step under lease, runs it, and records the outcome.
 *
 * Carries identifiers only. The step's arguments are read from the database or
 * the artifact disk inside the engine.
 */
final class ExecuteStep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly string $phase,
        public readonly int $sequence,
    ) {}

    public function handle(Engine $engine): void
    {
        $engine->executeStep($this->runId, $this->phase, $this->sequence);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['impex', 'run:'.$this->runId, 'step:'.$this->phase.':'.$this->sequence];
    }
}
