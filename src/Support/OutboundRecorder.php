<?php

declare(strict_types=1);

namespace JayI\Impex\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use JayI\Impex\Enums\Direction;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Records outbound HTTP as the mirror of an inbound channel.
 *
 * Returns a PendingRequest with a recorder middleware attached, so every call
 * an action makes lands in the ledger with its run and step attached. Without
 * this the ledger would only ever show half the picture.
 */
final class OutboundRecorder
{
    public function __construct(
        private readonly Http $http,
        private readonly MessageRecorder $recorder,
    ) {}

    /**
     * A client whose traffic is recorded against the given channel.
     */
    public function client(string $channel, ?string $runId = null, ?string $stepId = null): PendingRequest
    {
        $startedAt = null;

        return $this->http->withMiddleware(
            $this->middleware($channel, $runId, $stepId, $startedAt),
        );
    }

    private function middleware(string $channel, ?string $runId, ?string $stepId, ?float &$startedAt): Closure
    {
        return function (callable $handler) use ($channel, $runId, $stepId, &$startedAt): Closure {
            return function (RequestInterface $request, array $options) use (
                $handler,
                $channel,
                $runId,
                $stepId,
                &$startedAt,
            ): PromiseInterface {
                $startedAt = microtime(true);
                $body = (string) $request->getBody();

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use (
                        $request,
                        $body,
                        $channel,
                        $runId,
                        $stepId,
                        $startedAt,
                    ): ResponseInterface {
                        $this->recorder->record(
                            direction: Direction::Outbound,
                            channel: $channel,
                            transport: 'http',
                            endpoint: (string) $request->getUri(),
                            body: $body,
                            method: $request->getMethod(),
                            statusCode: $response->getStatusCode(),
                            runId: $runId,
                            stepId: $stepId,
                            durationMs: $this->elapsed($startedAt),
                        );

                        return $response;
                    },
                );
            };
        };
    }

    private function elapsed(?float $startedAt): ?int
    {
        if ($startedAt === null) {
            return null;
        }

        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
