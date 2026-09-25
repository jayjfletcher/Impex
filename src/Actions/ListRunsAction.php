<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Events\Action\RunsListedActionEvent;
use JayI\Impex\Events\Action\RunsListingActionEvent;
use JayI\Impex\Models\Run;

final class ListRunsAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(RunStatus::class)],
            'flow' => ['sometimes', 'string', 'max:191'],
            'trigger' => ['sometimes', Rule::enum(RunTrigger::class)],
            'owner_type' => ['sometimes', 'string', 'max:191', 'required_with:owner_id'],
            'owner_id' => ['sometimes', 'string', 'max:64', 'required_with:owner_type'],
            'tag' => ['sometimes', 'array'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
            'parent' => ['sometimes', 'string', 'max:26'],
            'cursor' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Model|null  $viewer  When given, only the runs they own.
     * @return CursorPaginator<int, Run>
     */
    public function execute(array $filters = [], ?Model $viewer = null): CursorPaginator
    {
        RunsListingActionEvent::dispatch($filters, $viewer);

        $result = $this->perform($filters, $viewer);

        RunsListedActionEvent::dispatch($result, $viewer);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Model|null  $viewer  When given, only the runs they own.
     * @return CursorPaginator<int, Run>
     */
    private function perform(array $filters = [], ?Model $viewer = null): CursorPaginator
    {
        $query = Run::query()->latest('created_at');

        if ($viewer !== null) {
            $query->whereOwnedBy($viewer);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['flow'])) {
            $query->where('flow', $filters['flow']);
        }

        if (isset($filters['trigger'])) {
            $query->where('trigger', $filters['trigger']);
        }

        if (isset($filters['owner_type'], $filters['owner_id'])) {
            $query->whereHas('owners', function (Builder $owners) use ($filters): void {
                $owners->where('owner_type', $filters['owner_type'])
                    ->where('owner_id', $filters['owner_id']);
            });
        }

        if (isset($filters['parent'])) {
            $query->where('parent_run_id', $filters['parent']);
        }

        if (isset($filters['since'])) {
            $query->where('created_at', '>=', $filters['since']);
        }

        if (isset($filters['until'])) {
            $query->where('created_at', '<=', $filters['until']);
        }

        /** @var array<string, string> $tags */
        $tags = $filters['tag'] ?? [];

        foreach ($tags as $key => $value) {
            $query->where('tags->'.$key, $value);
        }

        // Cursor, not offset: a ledger only grows, and deep offset pagination
        // degrades badly once it does.
        return $query->cursorPaginate(
            perPage: isset($filters['per_page']) ? (int) $filters['per_page'] : 25,
            cursor: is_string($filters['cursor'] ?? null) ? $filters['cursor'] : null,
        );
    }
}
