<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;

/**
 * A run you can act on, rather than one you have to look things up about.
 */
final class RunHandle
{
    public function __construct(public readonly Run $run) {}

    public function id(): string
    {
        return (string) $this->run->getKey();
    }

    public function signal(string $name, mixed $payload = null, ?string $idempotencyKey = null): Signal
    {
        return app(Impex::class)->signal($this->run, $name, $payload, $idempotencyKey);
    }

    /**
     * Deliver a signal, treating a finished run as a no-op.
     */
    public function signalIfRunning(string $name, mixed $payload = null, ?string $idempotencyKey = null): bool
    {
        return app(Impex::class)->signalIfRunning($this->run, $name, $payload, $idempotencyKey);
    }

    public function cancel(?string $reason = null): Run
    {
        return app(Impex::class)->cancel($this->run, $reason);
    }

    public function retry(): Run
    {
        return app(Impex::class)->retry($this->run);
    }

    public function result(): mixed
    {
        return app(Impex::class)->result($this->run->refresh());
    }

    public function refresh(): Run
    {
        return $this->run->refresh();
    }
}
