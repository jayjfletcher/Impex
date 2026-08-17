<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Mcp\Request;
use JayI\Impex\Models\Run;

abstract class RunRequest extends Request
{
    protected function run(): Run
    {
        /** @var string $id */
        $id = $this->get('run');

        return Run::query()->findOrFail($id);
    }
}
