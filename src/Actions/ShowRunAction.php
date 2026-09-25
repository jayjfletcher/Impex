<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Events\Action\RunShowingActionEvent;
use JayI\Impex\Events\Action\RunShownActionEvent;
use JayI\Impex\Models\Run;

final class ShowRunAction
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    public function execute(Run $run): Run
    {
        RunShowingActionEvent::dispatch($run);

        $result = $this->perform($run);

        RunShownActionEvent::dispatch($result);

        return $result;
    }

    private function perform(Run $run): Run
    {
        return $run->load(['owners', 'forwardSteps']);
    }
}
