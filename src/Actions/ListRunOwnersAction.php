<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use Illuminate\Database\Eloquent\Collection;
use JayI\Impex\Events\Action\RunOwnersListedActionEvent;
use JayI\Impex\Events\Action\RunOwnersListingActionEvent;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;

final class ListRunOwnersAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * @return Collection<int, RunOwner>
     */
    public function execute(Run $run): Collection
    {
        RunOwnersListingActionEvent::dispatch($run);

        $result = $this->perform($run);

        RunOwnersListedActionEvent::dispatch($run, $result);

        return $result;
    }

    /**
     * @return Collection<int, RunOwner>
     */
    private function perform(Run $run): Collection
    {
        return $run->owners()->get();
    }
}
