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
 * Replays a run and schedules whatever it reaches that is not yet recorded.
 *
 * Carries a ULID and nothing else, so the queue message can never approach
 * SQS's 256KB limit however large the run's payloads are.
 */
final class DriveRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $runId) {}

    public function handle(Engine $engine): void
    {
        $engine->drive($this->runId);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['impex', 'run:'.$this->runId];
    }
}
