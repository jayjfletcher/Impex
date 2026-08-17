<?php

declare(strict_types=1);

namespace JayI\Impex\Channels\Profiles;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;
use JayI\Impex\Contracts\ChannelProfile;

/**
 * Turns every authenticated request into a run.
 */
final class ProcessEverything implements ChannelProfile
{
    public function shouldProcess(Request $request, ChannelConfig $config): bool
    {
        return true;
    }
}
