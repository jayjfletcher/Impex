<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use Illuminate\Database\Eloquent\Model;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Events\Action\FlowRanActionEvent;
use JayI\Impex\Events\Action\FlowRunningActionEvent;
use JayI\Impex\Exceptions\DisabledFlowException;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Runtime\Engine;

final class RunFlowAction
{
    public function __construct(
        private readonly Impex $impex,
        private readonly FlowRegistry $flows,
        private readonly Engine $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'arguments' => ['sometimes', 'array'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:191'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'expires_in' => ['sometimes', 'integer', 'min:1'],
            'wait' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Model|null  $owner  Attached to the new run in the `owner` role.
     */
    public function execute(string $slug, array $data = [], RunTrigger $trigger = RunTrigger::Api, ?Model $owner = null): Run
    {
        FlowRunningActionEvent::dispatch($slug, $data, $trigger, $owner);

        $result = $this->perform($slug, $data, $trigger, $owner);

        FlowRanActionEvent::dispatch($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function perform(string $slug, array $data = [], RunTrigger $trigger = RunTrigger::Api, ?Model $owner = null): Run
    {
        if (! $this->flows->enabled($slug)) {
            throw DisabledFlowException::slug($slug);
        }

        /** @var array<int, mixed> $arguments */
        $arguments = $data['arguments'] ?? [];

        /** @var array<string, string> $tags */
        $tags = $data['tags'] ?? [];

        /** @var string|null $key */
        $key = $data['idempotency_key'] ?? null;

        $run = $this->impex->run(
            slug: $slug,
            arguments: array_values($arguments),
            trigger: $trigger,
            idempotencyKey: $key,
            tags: $tags,
            owners: $owner === null ? [] : ['owner' => $owner],
            version: isset($data['version']) ? (string) $data['version'] : null,
            expiresAt: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        );

        // A caller may ask to wait, bounded by impex.limits.sync_seconds — the
        // gateway will time out long before a flow of any size finishes.
        if (($data['wait'] ?? false) === true) {
            /** @var int $budget */
            $budget = config('impex.limits.sync_seconds', 15);

            return $this->engine->driveToCompletion($run, $budget);
        }

        // Report the run as it stands at response time. Under a real queue that
        // is still pending; under the sync driver the drive already ran.
        return $run->refresh();
    }
}
