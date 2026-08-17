<?php

declare(strict_types=1);

namespace JayI\Impex\Events;

final readonly class StepFailed
{
    public function __construct(public string $runId, public string $stepId) {}
}
