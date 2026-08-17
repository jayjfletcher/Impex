<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

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
        return $run->load(['owners', 'forwardSteps']);
    }
}
