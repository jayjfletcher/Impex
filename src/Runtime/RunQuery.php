<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Models\Run;

/**
 * A readable way to ask for runs.
 *
 * Everything here is reachable through plain Eloquent; this exists so the
 * common questions read as questions, and so `handles()` can hand back
 * something you can act on rather than something you have to act on.
 */
final class RunQuery
{
    /** @var Builder<Run> */
    private Builder $query;

    public function __construct()
    {
        $this->query = Run::query()->latest('created_at');
    }

    public function whereFlow(string $slug): self
    {
        $this->query->where('flow', $slug);

        return $this;
    }

    /**
     * @param  class-string  $class
     */
    public function whereFlowClass(string $class): self
    {
        $this->query->where('flow_class', $class);

        return $this;
    }

    public function whereTag(string $key, string $value): self
    {
        $this->query->where('tags->'.$key, $value);

        return $this;
    }

    public function whereTrigger(RunTrigger $trigger): self
    {
        $this->query->where('trigger', $trigger);

        return $this;
    }

    public function whereOwnedBy(Model $owner, ?string $role = null): self
    {
        $this->query->whereOwnedBy($owner, $role);

        return $this;
    }

    public function whereVersion(string $version): self
    {
        $this->query->where('flow_version', $version);

        return $this;
    }

    public function whereParent(string $runId): self
    {
        $this->query->where('parent_run_id', $runId);

        return $this;
    }

    public function running(): self
    {
        return $this->whereStatus(RunStatus::Running);
    }

    public function waiting(): self
    {
        return $this->whereStatus(RunStatus::Waiting);
    }

    public function completed(): self
    {
        return $this->whereStatus(RunStatus::Completed);
    }

    public function failed(): self
    {
        return $this->whereStatus(RunStatus::Failed);
    }

    public function cancelled(): self
    {
        return $this->whereStatus(RunStatus::Cancelled);
    }

    public function compensating(): self
    {
        return $this->whereStatus(RunStatus::Compensating);
    }

    /**
     * Runs that have not reached a terminal state.
     */
    public function active(): self
    {
        $this->query->active();

        return $this;
    }

    /**
     * Runs that can still be signalled.
     *
     * An alias of active(), and the one to reach for when looking for a run to
     * signal: `running()` would miss exactly the runs parked waiting for one.
     */
    public function signalable(): self
    {
        return $this->active();
    }

    public function whereStatus(RunStatus $status): self
    {
        $this->query->where('status', $status);

        return $this;
    }

    public function before(DateTimeInterface $moment): self
    {
        $this->query->where('created_at', '<', $moment);

        return $this;
    }

    public function since(DateTimeInterface $moment): self
    {
        $this->query->where('created_at', '>=', $moment);

        return $this;
    }

    /**
     * @return Collection<int, Run>
     */
    public function get(): Collection
    {
        return $this->query->get();
    }

    public function first(): ?Run
    {
        return $this->query->first();
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    /**
     * @return CursorPaginator<int, Run>
     */
    public function paginate(int $perPage = 25): CursorPaginator
    {
        return $this->query->cursorPaginate($perPage);
    }

    /**
     * The matching runs as handles you can act on.
     *
     * @return SupportCollection<int, RunHandle>
     */
    public function handles(): SupportCollection
    {
        return $this->get()->map(fn (Run $run): RunHandle => new RunHandle($run))->values();
    }

    /**
     * Drop to the underlying builder for anything this does not cover.
     *
     * @return Builder<Run>
     */
    public function builder(): Builder
    {
        return $this->query;
    }
}
