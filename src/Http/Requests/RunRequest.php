<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Requests;

use JayI\Impex\Http\Request;
use JayI\Impex\Models\Run;

abstract class RunRequest extends Request
{
    protected function run(): Run
    {
        $run = $this->route('run');

        if (! $run instanceof Run) {
            abort(404);
        }

        return $run;
    }
}
