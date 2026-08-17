<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use JayI\Impex\Contracts\UiTokenResolver;
use RuntimeException;

/**
 * Serves the dashboard shell.
 *
 * The dashboard is a pure client of the documented API — it uses no private
 * endpoint. That is the test that the API is genuinely complete.
 */
final class UiController
{
    public function __invoke(Request $request, Factory $view): View
    {
        $mode = (string) config('impex.ui.auth.mode', 'session');

        return $view->make('impex::app', [
            // Hex-escaped: the token comes from application code, so it must not
            // be able to close the script tag it is embedded in.
            'impexConfig' => (string) json_encode([
                'apiBase' => url((string) config('impex.routes.prefix', 'impex')),
                'basePath' => '/'.trim((string) config('impex.ui.path', 'impex/ui'), '/'),
                'auth' => [
                    'mode' => $mode,
                    'token' => $this->resolveToken($request, $mode),
                    // Public-client parameters only. There is no client secret
                    // in a PKCE flow, so nothing here is a credential.
                    'oauth' => $mode === 'oauth' ? [
                        'clientId' => (string) config('impex.ui.auth.oauth.client_id'),
                        'authorizeUrl' => url((string) config('impex.ui.auth.oauth.authorize_url', '/oauth/authorize')),
                        'tokenUrl' => url((string) config('impex.ui.auth.oauth.token_url', '/oauth/token')),
                        'scopes' => array_values((array) config('impex.ui.auth.oauth.scopes', [])),
                    ] : null,
                ],
                'csrfToken' => $request->hasSession() ? $request->session()->token() : null,
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
            'assetVersion' => $this->assetVersion(),
        ]);
    }

    private function resolveToken(Request $request, string $mode): ?string
    {
        if ($mode !== 'token') {
            return null;
        }

        /** @var class-string|null $class */
        $class = config('impex.ui.auth.token_resolver');

        if ($class === null) {
            return null;
        }

        $resolver = app($class);

        if (! $resolver instanceof UiTokenResolver) {
            throw new RuntimeException(sprintf(
                'The [impex.ui.auth.token_resolver] class [%s] must implement [%s].',
                $class,
                UiTokenResolver::class,
            ));
        }

        return $resolver->resolve($request);
    }

    /**
     * A cache-busting hash of the compiled bundle, if one has been built.
     */
    private function assetVersion(): ?string
    {
        $bundle = dirname(__DIR__, 3).'/public/app.js';

        if (! is_file($bundle)) {
            return null;
        }

        $hash = md5_file($bundle);

        return $hash === false ? null : $hash;
    }
}
