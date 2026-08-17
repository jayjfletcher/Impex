<?php

declare(strict_types=1);

namespace JayI\Impex\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JayI\Impex\Impex
 */
class Impex extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JayI\Impex\Impex::class;
    }
}
