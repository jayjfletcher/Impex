<?php

declare(strict_types=1);

namespace JayI\Impex\Tests;

/**
 * The dashboard mounts its route at boot, so enabling it has to happen before
 * the application is created rather than inside a test body.
 */
abstract class UiTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('impex.ui.enabled', true);
        $app['config']->set('impex.ui.middleware', []);
    }
}
