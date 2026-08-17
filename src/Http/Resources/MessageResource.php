<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JayI\Impex\Models\Message;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'step_id' => $this->step_id,
            'direction' => $this->direction->value,
            'channel' => $this->channel,
            'transport' => $this->transport,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'status_code' => $this->status_code,
            'headers' => $this->headers,
            'bytes' => $this->bytes,
            'body_preview' => $this->body_preview,
            'body_artifact_id' => $this->body_artifact_id,
            'signature_valid' => $this->signature_valid,
            'duration_ms' => $this->duration_ms,
            'error' => $this->error,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
