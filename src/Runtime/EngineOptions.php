<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The engine's tuning, read once and validated.
 *
 * These values are load-bearing rather than cosmetic — `lease_seconds` below
 * `max_step_seconds` means a legitimately slow step is reclaimed while it is
 * still working and runs twice — so they are checked here rather than
 * discovered in production.
 */
final class EngineOptions
{
    public function __construct(private readonly Config $config) {}

    /**
     * The working window for one step, below the platform's hard ceiling.
     */
    public function maxStepSeconds(): int
    {
        return $this->int('impex.limits.max_step_seconds', 840);
    }

    /**
     * Headroom before that window, at which a resumable step should yield.
     */
    public function resumeMargin(): int
    {
        return $this->int('impex.limits.resume_margin_seconds', 30);
    }

    /**
     * How long a claimed step is owned before another invocation may reclaim it.
     */
    public function leaseSeconds(): int
    {
        $lease = $this->int('impex.limits.lease_seconds', 900);

        if ($lease <= $this->maxStepSeconds()) {
            throw new RuntimeException(sprintf(
                'impex.limits.lease_seconds (%d) must exceed impex.limits.max_step_seconds (%d). '.
                'Below it, a step still legitimately working has its lease reclaimed and runs twice.',
                $lease,
                $this->maxStepSeconds(),
            ));
        }

        return $lease;
    }

    public function lockSeconds(): int
    {
        return $this->int('impex.limits.lock_seconds', 120);
    }

    public function maxResumptions(): int
    {
        return $this->int('impex.limits.max_resumptions', 10000);
    }

    public function fanOutMax(): int
    {
        return $this->int('impex.limits.fan_out_max', 100);
    }

    public function syncSeconds(): int
    {
        return $this->int('impex.limits.sync_seconds', 15);
    }

    public function maxQueueDelay(): int
    {
        return $this->int('impex.timers.max_queue_delay', 900);
    }

    public function timerClaimSeconds(): int
    {
        return $this->int('impex.timers.claim_seconds', 300);
    }

    public function timerBatch(): int
    {
        return $this->int('impex.timers.batch', 250);
    }

    /**
     * The default step deadline, if one is configured.
     */
    public function defaultStepDeadline(): ?Carbon
    {
        $seconds = $this->nullableInt('impex.deadlines.step');

        return $seconds === null ? null : Carbon::now()->addSeconds($seconds);
    }

    /**
     * The default run deadline, if one is configured.
     */
    public function defaultRunDeadline(): ?Carbon
    {
        $seconds = $this->nullableInt('impex.deadlines.run');

        return $seconds === null ? null : Carbon::now()->addSeconds($seconds);
    }

    /**
     * Retry backoff, growing with attempts and capped so nothing sleeps for
     * longer than a queue delay can express.
     */
    public function backoffSeconds(int $attempts): int
    {
        return min(60 * max(1, $attempts), 900);
    }

    public function lockKey(string $runId): string
    {
        return 'impex:run:'.$runId;
    }

    public function dirtyKey(string $runId): string
    {
        return 'impex:run:'.$runId.':dirty';
    }

    public function queueConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = $this->config->get('impex.queue.connection');

        return $connection;
    }

    public function queueName(): ?string
    {
        /** @var string|null $queue */
        $queue = $this->config->get('impex.queue.queue');

        return $queue;
    }

    private function int(string $key, int $default): int
    {
        /** @var int $value */
        $value = $this->config->get($key, $default);

        return $value;
    }

    private function nullableInt(string $key): ?int
    {
        /** @var int|null $value */
        $value = $this->config->get($key);

        return $value;
    }
}
