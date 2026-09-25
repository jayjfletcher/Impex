<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Events\Action\FlowsListedActionEvent;
use JayI\Impex\Events\Action\FlowsListingActionEvent;
use JayI\Impex\Flows\FlowRegistry;

final class ListFlowsAction
{
    public function __construct(private readonly FlowRegistry $flows) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        FlowsListingActionEvent::dispatch();

        $result = $this->perform();

        FlowsListedActionEvent::dispatch($result);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function perform(): array
    {
        $flows = [];

        foreach ($this->flows->all() as $slug => $class) {
            $flows[] = [
                'slug' => $slug,
                'class' => $class,
                'enabled' => $this->flows->enabled($slug),
                'schedule' => $this->flows->schedule($slug),
            ];
        }

        return $flows;
    }
}
