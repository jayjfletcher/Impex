<?php

declare(strict_types=1);

namespace JayI\Impex\Tests\Fixtures;

use JayI\Impex\Contracts\BatchSource;
use JayI\Impex\Contracts\Resumable;
use JayI\Impex\Flows\Concerns\CanResume;
use JayI\Impex\Flows\Flow;
use JayI\Impex\Flows\ResumableAction;
use JayI\Impex\Runtime\BatchChunk;
use JayI\Impex\Runtime\BatchChunkItem;
use JayI\Impex\Runtime\Resume;
use RuntimeException;

/**
 * A counter every fixture action writes to, so tests can assert how many times
 * a step's side effect actually fired — the thing idempotency is about.
 */
final class Calls
{
    /** @var array<string, int> */
    public static array $counts = [];

    /** @var array<string, mixed> */
    public static array $options = [];

    public static function record(string $key): int
    {
        self::$counts[$key] = (self::$counts[$key] ?? 0) + 1;

        return self::$counts[$key];
    }

    public static function count(string $key): int
    {
        return self::$counts[$key] ?? 0;
    }

    public static function reset(): void
    {
        self::$counts = [];
        self::$options = [];
    }
}

final class AddOne
{
    /**
     * @return array<string, int>
     */
    public function execute(int $value): array
    {
        Calls::record('add-one');

        return ['value' => $value + 1];
    }
}

final class Double
{
    /**
     * @return array<string, int>
     */
    public function execute(int $value): array
    {
        Calls::record('double');

        return ['value' => $value * 2];
    }
}

final class AlwaysFails
{
    public function execute(): never
    {
        Calls::record('always-fails');

        throw new RuntimeException('upstream refused the request');
    }
}

final class Rollback
{
    /**
     * @return array<string, bool>
     */
    public function execute(string $of): array
    {
        Calls::record('rollback:'.$of);

        return ['rolled_back' => true];
    }
}

final class ReturnsLargePayload
{
    /**
     * @return array<string, string>
     */
    public function execute(): array
    {
        Calls::record('large');

        return ['blob' => str_repeat('x', 200_000)];
    }
}

/**
 * Yields a cursor until it has walked its pages, proving a step can span more
 * invocations than the platform's execution ceiling allows.
 */
final class SeedsInPages implements Resumable
{
    use CanResume;

    /**
     * @return array<string, int>|Resume
     */
    public function execute(int $pages): mixed
    {
        $page = (int) ($this->cursor() ?? 0);

        Calls::record('seed');

        $page++;

        if ($page < $pages) {
            return $this->yieldTo((string) $page);
        }

        return ['pages' => $page];
    }

    protected function shouldYield(): bool
    {
        // Deterministic stand-in for the wall clock: the fixture yields on
        // every page rather than waiting for a real deadline.
        return true;
    }
}

final class LinearFlow extends Flow
{
    /**
     * @return array<string, int>
     */
    public function handle(int $start): array
    {
        $first = $this->action(AddOne::class, $start)->run();
        $second = $this->action(Double::class, $first['value'])->run();

        return ['value' => $second['value']];
    }
}

final class ParallelFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $start): array
    {
        [$a, $b] = $this->parallel()
            ->action(AddOne::class, $start)
            ->action(Double::class, $start)
            ->run();

        return ['sum' => $a['value'] + $b['value']];
    }
}

final class CompensatingFlow extends Flow
{
    /**
     * @return array<string, int>
     */
    public function handle(): array
    {
        $this->action(AddOne::class, 1)
            ->compensateWith(Rollback::class, 'add-one')
            ->run();

        $this->action(AlwaysFails::class)->run();

        return ['unreachable' => 1];
    }
}

final class SideEffectFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $stamp = $this->sideEffect('stamp', fn (): int => Calls::record('side-effect'));

        $this->action(AddOne::class, 1)->run();

        return ['stamp' => $stamp];
    }
}

final class SignalFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $this->action(AddOne::class, 1)->run();

        $approval = $this->awaitSignal('approval');

        return ['approval' => $approval];
    }
}

final class LargePayloadFlow extends Flow
{
    /**
     * @return array<string, int>
     */
    public function handle(): array
    {
        $large = $this->action(ReturnsLargePayload::class)->run();

        return ['length' => strlen($large['blob'])];
    }
}

final class ResumingFlow extends Flow
{
    /**
     * @return array<string, int>
     */
    public function handle(int $pages): array
    {
        return $this->action(SeedsInPages::class, $pages)->run();
    }
}

final class SleepingFlow extends Flow
{
    /**
     * @return array<string, bool>
     */
    public function handle(): array
    {
        $this->action(AddOne::class, 1)->run();

        // Far beyond any queue's delay ceiling: this must become a timer row,
        // not a delayed job.
        $this->sleepUntil(now()->addDays(3));

        Calls::record('after-sleep');

        return ['woke' => true];
    }
}

/**
 * Yields the same cursor forever. The engine must notice and fail rather than
 * re-dispatching itself until it exhausts the account's concurrency.
 */
final class NeverAdvances extends ResumableAction
{
    public function execute(): mixed
    {
        Calls::record('never-advances');

        return $this->yieldTo('stuck');
    }

    protected function shouldYield(): bool
    {
        return true;
    }
}

final class StallingFlow extends Flow
{
    /**
     * @return array<string, bool>
     */
    public function handle(): array
    {
        $this->action(NeverAdvances::class)->run();

        return ['unreachable' => true];
    }
}

final class FanOutFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $count): array
    {
        $items = $this->action(MakeItems::class, $count)->run();

        $results = $this->fanOut($items, fn (array $item) => $this->action(AddOne::class, $item['n']))
            ->keyBy(fn (array $item): string => $item['sku'])
            ->run();

        return ['values' => array_column($results, 'value')];
    }
}

final class UnkeyedFanOutFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $count): array
    {
        $items = $this->action(MakeItems::class, $count)->run();

        $results = $this->fanOut($items, fn (array $item) => $this->action(AddOne::class, $item['n']))->run();

        return ['values' => array_column($results, 'value')];
    }
}

final class MakeItems
{
    /**
     * @return array<int, array{sku: string, n: int}>
     */
    public function execute(int $count): array
    {
        Calls::record('make-items');

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = ['sku' => sprintf('SKU-%03d', $i), 'n' => $i];
        }

        return $items;
    }
}

/**
 * Streams three pages of two items each, resuming from a numeric cursor.
 */
final class PagedSource implements BatchSource
{
    public function __construct(private readonly int $pages = 3) {}

    public function chunk(?string $cursor, int $size): BatchChunk
    {
        $page = (int) ($cursor ?? 0);

        Calls::record('source-chunk');

        if ($page >= $this->pages) {
            return BatchChunk::last();
        }

        $items = [];

        for ($i = 0; $i < 2; $i++) {
            $key = sprintf('P%d-%d', $page, $i);
            $items[] = new BatchChunkItem($key, ['key' => $key, 'n' => ($page * 2) + $i]);
        }

        return BatchChunk::of($items, (string) ($page + 1));
    }
}

final class EnrichItem
{
    /**
     * @param  array{key: string, n: int}  $item
     * @return array<string, mixed>
     */
    public function execute(array $item): array
    {
        Calls::record('enrich');

        if (($item['key'] ?? '') === 'P1-1') {
            throw new RuntimeException('this one always fails');
        }

        return ['key' => $item['key'], 'doubled' => $item['n'] * 2];
    }
}

final class BatchFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $pages, float $allowFailures = 0.5): array
    {
        $summary = $this->batch(PagedSource::class, $pages)
            ->using(EnrichItem::class)
            ->chunk(2)
            ->allowFailures($allowFailures)
            ->run();

        Calls::record('after-batch');

        return $summary;
    }
}

final class Ingest
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        Calls::record('ingest');

        return ['received' => $payload];
    }
}

final class PayloadFlow extends Flow
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->action(Ingest::class, $payload)->run();
    }
}

final class TimeoutSignalFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $decision = $this->signal('approval')
            ->timeoutAfter(now()->addDays(3))
            ->default(['approved' => false, 'timed_out' => true])
            ->wait();

        return $decision;
    }
}

final class StrictSignalFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return (array) $this->signal('approval')
            ->timeoutAfter(now()->addDays(3))
            ->orFail()
            ->wait();
    }
}

final class NullSignalFlow extends Flow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $decision = $this->signal('approval')
            ->timeoutAfter(now()->addDays(3))
            ->default(['timed_out' => true])
            ->wait();

        return ['received' => $decision, 'timed_out' => false];
    }
}
