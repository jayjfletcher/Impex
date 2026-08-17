<?php

declare(strict_types=1);

namespace JayI\Impex\Events;

final readonly class RunFailed
{
    public function __construct(public string $runId) {}
}
