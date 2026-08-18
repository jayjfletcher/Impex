<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JayI\Impex\Models\RunStep;

/**
 * @mixin RunStep
 */
final class RunStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'name' => $this->name,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'resumptions' => $this->resumptions,
            'undone' => $this->undone,
            'undoes_sequence' => $this->undoes_sequence,
            'unit_id' => $this->unit_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'error' => $this->error,
            // Payloads are deliberately not inlined: a step result can be
            // hundreds of megabytes on the artifact disk.
            'has_result' => $this->result !== null || $this->result_artifact_id !== null,
            'result_artifact_id' => $this->result_artifact_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
