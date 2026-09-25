<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Events\Action\RunStepsListedActionEvent;
use JayI\Impex\Events\Action\RunStepsListingActionEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

final class ListRunStepsAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'phase' => ['sometimes', Rule::enum(StepPhase::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, RunStep>
     */
    public function execute(Run $run, array $filters = []): Collection
    {
        RunStepsListingActionEvent::dispatch($run, $filters);

        $result = $this->perform($run, $filters);

        RunStepsListedActionEvent::dispatch($run, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, RunStep>
     */
    private function perform(Run $run, array $filters = []): Collection
    {
        return $run->steps()
            ->when(isset($filters['phase']), fn (Builder $query) => $query->where('phase', $filters['phase']))
            ->orderBy('phase')
            ->orderBy('sequence')
            ->get();
    }
}
