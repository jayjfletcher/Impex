# The ledger

Every payload that crosses the application boundary becomes a row in
`impex_messages`, linked to the run and step that caused it. Bodies above the
inline threshold go to the artifact disk, so a 40MB supplier feed does not land
in a text column.

## Inbound: channels

A channel is a named boundary configuration.

```php
// config/impex.php
'channels' => [
    'supplier-feed' => [
        'direction' => 'inbound',
        'signing_secret' => env('IMPEX_SUPPLIER_SECRET'),
        'signature_header' => 'X-Signature',
        'signature_validator' => \JayI\Impex\Channels\Validators\HmacSha256Validator::class,
        'profile' => \JayI\Impex\Channels\Profiles\ProcessEverything::class,
        'idempotency_header' => 'X-Request-Id',
        'flow' => 'extract-products',
        'store_headers' => ['content-type', 'x-request-id'],
        'queue' => 'impex-ingest',
        'path' => null,   // defaults to channels/supplier-feed
    ],
],
```

`POST /impex/channels/supplier-feed` then:

1. records the request in the ledger,
2. validates the signature,
3. applies the profile,
4. responds `202` immediately,
5. queues the bound flow with the decoded JSON body as its first argument.

```json
{ "message": "Accepted.", "message_id": "01JQ…", "run_id": "01JQ…" }
```

### A rejected request is still recorded

A bad signature returns `403` and starts no run — but the message row is written
first, with `signature_valid: false`. It is evidence of what an upstream sent,
which is exactly what a ledger is for.

### Headers

Only the headers named in `store_headers` are stored. Webhook headers routinely
carry bearer tokens and signatures, and the dashboard renders this table. Use
`['*']` to store everything, deliberately.

### Idempotency

`unique(channel, idempotency_key)` — a plain index cannot dedupe concurrent
redelivery, because both requests pass the `SELECT` and both insert. Scoped to
the channel because two suppliers may legitimately reuse a key.

Set `idempotency_header` and a redelivered webhook returns the original message
and run instead of starting a second one.

### Custom signature validation

Every upstream signs differently.

```php
namespace App\Impex;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;
use JayI\Impex\Contracts\SignatureValidator;

final class StripeSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, ChannelConfig $config): bool
    {
        $header = (string) $request->header($config->signatureHeader);

        [$timestamp, $signature] = $this->parse($header);

        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            (string) $config->signingSecret,
        );

        // Constant-time: a timing-variable comparison leaks the signature one
        // byte at a time.
        return hash_equals($expected, $signature)
            && abs(time() - (int) $timestamp) < 300;
    }
}
```

```php
'signature_validator' => \App\Impex\StripeSignatureValidator::class,
```

### Filtering with a profile

```php
namespace App\Impex;

use Illuminate\Http\Request;
use JayI\Impex\Channels\ChannelConfig;
use JayI\Impex\Contracts\ChannelProfile;

final class OnlyProductEvents implements ChannelProfile
{
    public function shouldProcess(Request $request, ChannelConfig $config): bool
    {
        return str_starts_with((string) $request->json('type'), 'product.');
    }
}
```

A request the profile rejects is still recorded and still answered `202`. It
simply starts no run.

## Outbound

The mirror. `Impex::http()` returns a `PendingRequest` with a recorder
middleware attached, so every call inside an action lands in the ledger with its
run and step attached, timing included.

```php
final class FetchPricing
{
    public function __construct(private readonly Impex $impex) {}

    public function execute(array $skus, string $runId): array
    {
        return $this->impex->http('vendor-api', $runId)
            ->post('https://vendor.test/quote', ['skus' => $skus])
            ->json();
    }
}
```

For egress the HTTP middleware cannot see — a file drop, an SFTP put, a message
published to another system:

```php
Impex::record(
    channel: 'sftp-drop',
    endpoint: 'sftp://partner.test/in/catalogue.csv',
    body: $csv,
    transport: 'file',
    runId: $runId,
);
```

## Reading the ledger

```php
use JayI\Impex\Enums\Direction;

Message::query()->where('direction', Direction::Inbound)->latest('occurred_at')->get();

$run->messages;                       // everything this run caused

Impex::body($message);                // the full body, from the column or the disk
$message->body_preview;               // the first 2KB, for a list view
```

```
GET impex/messages?direction=inbound&channel=supplier-feed
GET impex/messages/{message}
```

## Retention

```php
'retention' => [
    'messages_days' => 90,
    'artifacts_days' => 365,   // never shorter than what points at it
],
```

Artifacts must never expire before the rows referencing them, or a failed run
loses the payloads you would open it to read. `impex:prune` deletes in
dependency order.
