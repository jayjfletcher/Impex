<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

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
        return $this->impex->retry($run);
    }
}
