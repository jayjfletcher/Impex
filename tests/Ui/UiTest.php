<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use JayI\Impex\Contracts\UiTokenResolver;

it('hands the dashboard its API base and auth mode', function (): void {
    $response = $this->get('/impex/ui')->assertOk();

    $response->assertSee('window.ImpexConfig', false)
        ->assertSee('"basePath":"/impex/ui"', false)
        ->assertSee('"mode":"session"', false);
});

it('serves the same shell for deep links so client routing works', function (): void {
    $this->get('/impex/ui/runs/01JQQQQQQQQQQQQQQQQQQQQQQQ')
        ->assertOk()
        ->assertSee('id="impex"', false);
});

it('never emits a token in session mode', function (): void {
    $this->get('/impex/ui')->assertOk()->assertSee('"token":null', false);
});

it('resolves a bearer token when configured for token mode', function (): void {
    config()->set('impex.ui.auth.mode', 'token');
    config()->set('impex.ui.auth.token_resolver', TestTokenResolver::class);

    $this->get('/impex/ui')->assertOk()->assertSee('"token":"tok_123"', false);
});

it('escapes a token so it cannot break out of the script tag', function (): void {
    config()->set('impex.ui.auth.mode', 'token');
    config()->set('impex.ui.auth.token_resolver', HostileTokenResolver::class);

    $response = $this->get('/impex/ui')->assertOk();

    // The token comes from application code, so it is embedded hex-escaped:
    // the literal closing tag must never reach the document.
    expect($response->content())->not->toContain('</script><script>')
        ->and($response->content())->toContain('\u003C/script');
});

it('emits the public-client parameters in oauth mode', function (): void {
    config()->set('impex.ui.auth.mode', 'oauth');
    config()->set('impex.ui.auth.oauth.client_id', '9d1f-public');
    config()->set('impex.ui.auth.oauth.scopes', ['impex:read', 'impex:write']);

    $response = $this->get('/impex/ui')->assertOk();

    $response->assertSee('"mode":"oauth"', false)
        ->assertSee('"clientId":"9d1f-public"', false)
        ->assertSee('"authorizeUrl":"http://localhost/oauth/authorize"', false)
        ->assertSee('"tokenUrl":"http://localhost/oauth/token"', false)
        ->assertSee('"scopes":["impex:read","impex:write"]', false);
});

it('emits no oauth parameters unless oauth is the active mode', function (): void {
    config()->set('impex.ui.auth.oauth.client_id', '9d1f-public');

    // PKCE has no client secret, but leaking the client id and endpoints when
    // they are not in use is still noise the page does not need.
    $this->get('/impex/ui')->assertOk()->assertSee('"oauth":null', false);
});

it('rejects a token resolver that does not implement the contract', function (): void {
    config()->set('impex.ui.auth.mode', 'token');
    config()->set('impex.ui.auth.token_resolver', stdClass::class);

    $this->get('/impex/ui')->assertStatus(500);
});

it('ships a compiled bundle so a host app needs no build step', function (): void {
    $public = dirname(__DIR__, 2).'/public';

    expect(is_file($public.'/app.js'))->toBeTrue()
        ->and(is_file($public.'/app.css'))->toBeTrue();
});

final class TestTokenResolver implements UiTokenResolver
{
    public function resolve(Request $request): ?string
    {
        return 'tok_123';
    }
}

final class HostileTokenResolver implements UiTokenResolver
{
    public function resolve(Request $request): ?string
    {
        return '</script><script>alert(1)</script>';
    }
}
