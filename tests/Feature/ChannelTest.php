<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Impex;
use JayI\Impex\Models\Message;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\PayloadFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', ['ingest' => PayloadFlow::class]);
    config()->set('impex.channels', [
        'supplier-feed' => [
            'direction' => 'inbound',
            'signing_secret' => 'shhh',
            'signature_header' => 'X-Signature',
            'flow' => 'ingest',
            'store_headers' => ['content-type', 'x-request-id'],
            'idempotency_header' => 'X-Request-Id',
        ],
    ]);
});

function sign(array $payload): array
{
    $body = json_encode($payload);

    return [$body, hash_hmac('sha256', (string) $body, 'shhh')];
}

it('records an inbound webhook and starts the flow bound to the channel', function (): void {
    [$body, $signature] = sign(['sku' => 'ABC-1']);

    $response = $this->call(
        'POST',
        '/impex/channels/supplier-feed',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_REQUEST_ID' => 'req-1',
        ],
        content: $body,
    );

    $response->assertStatus(202);

    $message = Message::query()->firstOrFail();

    expect($message->direction)->toBe(Direction::Inbound)
        ->and($message->channel)->toBe('supplier-feed')
        ->and($message->signature_valid)->toBeTrue()
        ->and($message->body_preview)->toBe($body)
        ->and($message->run_id)->not->toBeNull()
        // Only the headers the channel asked for: webhook headers routinely
        // carry credentials.
        ->and(array_keys($message->headers))->toBe(['content-type', 'x-request-id']);

    expect($message->run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(Calls::count('ingest'))->toBe(1);
});

it('records a rejected signature but starts no run', function (): void {
    [$body] = sign(['sku' => 'ABC-1']);

    $this->call(
        'POST',
        '/impex/channels/supplier-feed',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'wrong'],
        content: $body,
    )->assertStatus(403);

    $message = Message::query()->firstOrFail();

    // Still recorded: an invalid signature is evidence of what an upstream
    // sent, which is exactly what the ledger is for.
    expect($message->signature_valid)->toBeFalse()
        ->and($message->run_id)->toBeNull()
        ->and(Calls::count('ingest'))->toBe(0);
});

it('dedupes a redelivered webhook by its idempotency header', function (): void {
    [$body, $signature] = sign(['sku' => 'ABC-1']);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
        'HTTP_X_REQUEST_ID' => 'req-1',
    ];

    $this->call('POST', '/impex/channels/supplier-feed', server: $server, content: $body)->assertStatus(202);
    $this->call('POST', '/impex/channels/supplier-feed', server: $server, content: $body)->assertStatus(202);

    expect(Message::query()->count())->toBe(1)
        ->and(Calls::count('ingest'))->toBe(1);
});

it('records outbound calls against the ledger', function (): void {
    Http::fake(['vendor.test/*' => Http::response(['ok' => true], 200)]);

    app(Impex::class)->http('vendor-api')->post('https://vendor.test/quote', ['sku' => 'ABC-1']);

    $message = Message::query()->where('direction', Direction::Outbound)->firstOrFail();

    expect($message->channel)->toBe('vendor-api')
        ->and($message->method)->toBe('POST')
        ->and($message->status_code)->toBe(200)
        ->and($message->endpoint)->toBe('https://vendor.test/quote')
        ->and($message->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records non-HTTP egress the middleware cannot see', function (): void {
    app(Impex::class)->record(
        channel: 'sftp-drop',
        endpoint: 'sftp://partner.test/in/catalogue.csv',
        body: "sku,price\nABC-1,10.00\n",
    );

    $message = Message::query()->firstOrFail();

    expect($message->transport)->toBe('file')
        ->and($message->direction)->toBe(Direction::Outbound)
        ->and($message->bytes)->toBe(22);
});
