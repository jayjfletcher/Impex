# Dashboard

```php
// config/impex.php
'ui' => [
    'enabled' => true,
    'path' => 'impex/ui',
    'middleware' => ['web', 'auth', 'can:viewImpex'],
],
```

```bash
php artisan vendor:publish --tag=impex-assets
```

A prebuilt Vue 3 bundle ships in `public/`, so a host application needs no build
step.

**It ships disabled.** The dashboard renders every payload that has crossed the
boundary, so mounting it is an explicit decision and it needs real middleware.

## Screens

| | |
|---|---|
| **Runs** | Filterable by status, flow, owner and tag. Cursor paginated. |
| **Run detail** | Step timeline, rollback path on a dashed rail, owners, and the messages the run caused. Signal delivery when the run is waiting. |
| **Messages** | The ledger in both directions, with signature status and body previews. |
| **Flows** | The catalogue, with a trigger form per flow. |
| **Channels** | Configured endpoints, flagging any that accept unsigned requests. |

The timeline shows `resumed N×` on any step that checkpointed, which is how you
tell a step that spanned many invocations from one slow one.

## Auth modes

Two layers, and they are independent:

- `ui.middleware` decides who may **load** the dashboard.
- `routes.middleware` decides who may **call the API** it talks to.
- `ui.auth.mode` decides **how the dashboard authenticates** to that API.

| Mode | The dashboard sends | Use when |
|---|---|---|
| `session` | same-origin cookies + CSRF token | the dashboard sits behind your `web` middleware — the default |
| `token` | a bearer token from a `UiTokenResolver` | your API is behind token auth and you can mint a short-lived token server-side |
| `oauth` | a bearer token from authorization-code + PKCE | your API is behind Passport or another OAuth server |
| `custom` | whatever `window.ImpexAuth` returns | anything else |

### `session`

Nothing to configure. The CSRF token is embedded in the page and sent with every
request.

### `token`

```php
namespace App\Impex;

use Illuminate\Http\Request;
use JayI\Impex\Contracts\UiTokenResolver;

final class DashboardToken implements UiTokenResolver
{
    public function resolve(Request $request): ?string
    {
        // Short-lived: this token is embedded in the page.
        return $request->user()?->createToken('impex-dashboard', ['impex:read'], now()->addHour())->plainTextToken;
    }
}
```

```php
'auth' => ['mode' => 'token', 'token_resolver' => \App\Impex\DashboardToken::class],
```

### `oauth` — with Laravel Passport

The dashboard is a browser app with no server side of its own, so it authorises
as a **public client** using PKCE. No client secret is ever in the bundle.

```bash
php artisan passport:client --public
# Redirect URI: https://your-app.test/impex/ui
```

```php
'routes' => [
    'middleware' => ['api', 'auth:api'],
],

'ui' => [
    'enabled' => true,
    'middleware' => ['web', 'auth', 'can:viewImpex'],
    'auth' => [
        'mode' => 'oauth',
        'oauth' => [
            'client_id' => env('IMPEX_OAUTH_CLIENT_ID'),
            'authorize_url' => '/oauth/authorize',
            'token_url' => '/oauth/token',
            'scopes' => ['impex:read', 'impex:write'],
        ],
    ],
],
```

The redirect URI must match the dashboard's mounted path exactly, because that
is where the driver sends the browser back. Move `ui.path`, update the client.

Tokens live in `sessionStorage`, renew through the refresh grant a minute before
expiry, and fall back to a full authorize redirect when refresh fails. A 401
mid-session is one silent retry, not an error the operator has to reason about.

### `custom`

```html
<script>
window.ImpexAuth = {
    async headers(refresh = false) {
        return { Authorization: `Bearer ${await myApp.token(refresh)}` }
    },
    credentials: 'omit',
    retriesOn401: () => true,
    async boot() { /* optional setup before mount */ },
}
</script>
<!-- then the dashboard bundle -->
```

The driver contract: `headers(refresh)` required and may be async; `credentials`,
`boot()`, and `retriesOn401()` optional.

## Building from source

```bash
npm install
npm run build      # compiles into public/
npm run dev
```

The compiled bundle is committed, which is what lets a host application install
the package and publish assets without a Node toolchain.

## Security notes

- The config blob is embedded in a `<script>` tag and hex-escaped, so a token
  from your resolver cannot close the tag.
- `<meta name="robots" content="noindex, nofollow">` is set on the shell.
- The dashboard uses no private endpoint — it is a pure client of the documented
  API. That is the test that the API is complete.
