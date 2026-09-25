<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Events\Action\MessagesListedActionEvent;
use JayI\Impex\Events\Action\MessagesListingActionEvent;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;

final class ListMessagesAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::enum(Direction::class)],
            'channel' => ['sometimes', 'string', 'max:191'],
            'run' => ['sometimes', 'string', 'max:26'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
            'cursor' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Model|null  $viewer  When given, only messages of runs they own.
     * @return CursorPaginator<int, Message>
     */
    public function execute(array $filters = [], ?Model $viewer = null): CursorPaginator
    {
        MessagesListingActionEvent::dispatch($filters, $viewer);

        $result = $this->perform($filters, $viewer);

        MessagesListedActionEvent::dispatch($result, $viewer);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Model|null  $viewer  When given, only messages of runs they own.
     * @return CursorPaginator<int, Message>
     */
    private function perform(array $filters = [], ?Model $viewer = null): CursorPaginator
    {
        $query = Message::query()->latest('occurred_at');

        // A message with no run has no owner, so a viewer never sees it.
        if ($viewer !== null) {
            $query->whereHas('run', function (Builder $runs) use ($viewer): void {
                /** @var Builder<Run> $runs */
                $runs->whereOwnedBy($viewer);
            });
        }

        foreach (['direction' => 'direction', 'channel' => 'channel', 'run' => 'run_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (isset($filters['since'])) {
            $query->where('occurred_at', '>=', $filters['since']);
        }

        if (isset($filters['until'])) {
            $query->where('occurred_at', '<=', $filters['until']);
        }

        return $query->cursorPaginate(
            perPage: isset($filters['per_page']) ? (int) $filters['per_page'] : 25,
            cursor: is_string($filters['cursor'] ?? null) ? $filters['cursor'] : null,
        );
    }
}
