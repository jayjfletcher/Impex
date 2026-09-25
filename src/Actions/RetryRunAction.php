<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Events\Action\RunRetriedActionEvent;
use JayI\Impex\Events\Action\RunRetryingActionEvent;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;

final class RetryRunAction
{
    public function __construct(private readonly Impex $impex) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    public function execute(Run $run): Run
    {
        RunRetryingActionEvent::dispatch($run);

        $result = $this->perform($run);

        RunRetriedActionEvent::dispatch($result);

        return $result;
    }

    private function perform(Run $run): Run
    {
        return $this->impex->retry($run);
    }
}
