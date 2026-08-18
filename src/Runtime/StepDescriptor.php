<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use DateTimeInterface;
use JayI\Impex\Enums\RollbackFailure;
use JayI\Impex\Enums\StepType;

/**
 * The immutable description of one operation a flow asked for.
 *
 * Produced by the DSL builders during replay and compared against the recorded
 * history to detect divergence.
 */
final readonly class StepDescriptor
{
    /**
     * @param  array<int, mixed>  $arguments
     * @param  array{action: string, arguments: array<int, mixed>}|null  $rollback
     */
    public function __construct(
        public StepType $type,
        public string $name,
        public array $arguments = [],
        public ?array $rollback = null,
        public int $maxAttempts = 1,
        public bool $continueOnFailure = false,
        public mixed $fallback = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?string $unitId = null,
        public RollbackFailure $rollbackFailure = RollbackFailure::Halt,
        public bool $rollbackTogether = false,
    ) {}
}
