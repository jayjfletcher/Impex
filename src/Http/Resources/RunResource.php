<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JayI\Impex\Models\Run;

/**
 * @mixin Run
 */
final class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow' => $this->flow,
            'status' => $this->status->value,
            'trigger' => $this->trigger->value,
            'idempotency_key' => $this->idempotency_key,
            'tags' => $this->tags,
            'error' => $this->error,
            'parent_run_id' => $this->parent_run_id,
            'owners' => RunOwnerResource::collection($this->whenLoaded('owners')),
            'steps' => RunStepResource::collection($this->whenLoaded('forwardSteps')),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
