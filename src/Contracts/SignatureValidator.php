<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;

/**
 * Decides whether an inbound request really came from the sender it claims.
 *
 * Every upstream signs differently, so this is a real extension point: one
 * implementation ships, others are written by the host application.
 */
interface SignatureValidator
{
    public function isValid(Request $request, ChannelConfig $config): bool;
}
