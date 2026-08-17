<?php

declare(strict_types=1);

namespace JayI\Impex\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Carbon;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Events\MessageRecorded;
use JayI\Impex\Models\Message;

/**
 * Writes the ledger.
 *
 * Every payload that crosses the application boundary becomes a row here,
 * linked to the run and step that caused it. Bodies above the inline threshold
 * go to the artifact disk, so a 40MB supplier feed does not land in a text
 * column.
 */
final class MessageRecorder
{
    public function __construct(
        private readonly PayloadStore $payloads,
        private readonly Config $config,
        private readonly Events $events,
    ) {}

    /**
     * Record one crossing.
     *
     * @param  array<string, mixed>|null  $headers
     * @param  array<string, mixed>|null  $error
     */
    public function record(
        Direction $direction,
        string $channel,
        string $transport,
        string $endpoint,
        ?string $body = null,
        ?string $method = null,
        ?int $statusCode = null,
        ?array $headers = null,
        ?string $runId = null,
        ?string $stepId = null,
        ?bool $signatureValid = null,
        ?int $durationMs = null,
        ?array $error = null,
        ?string $idempotencyKey = null,
    ): Message {
        $body ??= '';

        // Scoped to the channel, because two suppliers may legitimately reuse
        // a key. A plain index cannot dedupe concurrent redelivery.
        if ($idempotencyKey !== null) {
            $existing = Message::query()
                ->where('channel', $channel)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof Message) {
                return $existing;
            }
        }

        $stored = $this->payloads->put($body, ArtifactKind::Payload, ['run_id' => $runId]);

        return tap(Message::query()->create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'direction' => $direction,
            'channel' => $channel,
            'transport' => $transport,
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'headers' => $headers,
            'body_artifact_id' => $stored['artifact_id'],
            'body_preview' => $this->preview($body),
            'bytes' => strlen($body),
            'signature_valid' => $signatureValid,
            'duration_ms' => $durationMs,
            'error' => $error,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => Carbon::now(),
        ]), function (Message $message): void {
            $this->events->dispatch(new MessageRecorded((string) $message->getKey()));
        });
    }

    /**
     * Read a recorded body back.
     */
    public function body(Message $message): ?string
    {
        if ($message->body_artifact_id === null) {
            return $message->body_preview;
        }

        $body = $this->payloads->get(null, $message->body_artifact_id);

        return is_string($body) ? $body : null;
    }

    /**
     * Keep only the headers the channel asked to store.
     *
     * Webhook headers routinely carry bearer tokens and signatures; storing
     * them wholesale would put credentials in a table the dashboard renders.
     *
     * @param  array<string, array<int, string|null>>  $headers
     * @param  array<int, string>  $keep
     * @return array<string, mixed>|null
     */
    public function filterHeaders(array $headers, array $keep): ?array
    {
        if ($keep === []) {
            return null;
        }

        if (in_array('*', $keep, true)) {
            return $headers;
        }

        $wanted = array_map(fn (string $header): string => strtolower($header), $keep);

        return array_intersect_key(
            array_change_key_case($headers),
            array_flip($wanted),
        );
    }

    private function preview(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        /** @var int $length */
        $length = $this->config->get('impex.messages.preview_bytes', 2048);

        return mb_strcut($body, 0, $length);
    }
}
