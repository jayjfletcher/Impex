<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;

/**
 * Decides which inbound requests are worth turning into runs.
 *
 * Filtering by event type, tenant, or payload shape is inherently
 * application-specific, so this is a real extension point. A request that is
 * not processed is still recorded in the ledger — it just does not start a run.
 */
interface ChannelProfile
{
    public function shouldProcess(Request $request, ChannelConfig $config): bool;
}
