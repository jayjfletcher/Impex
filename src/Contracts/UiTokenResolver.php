<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use Illuminate\Http\Request;

/**
 * Mints the bearer token the dashboard sends to the API.
 *
 * Only used when `impex.ui.auth.mode` is `token`, for applications whose API
 * sits behind token auth rather than session cookies.
 */
interface UiTokenResolver
{
    public function resolve(Request $request): ?string;
}
