<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Exceptions\DisabledFlowException;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;

final class RunFlowAction
{
    public function __construct(
        private readonly Impex $impex,
        private readonly FlowRegistry $flows,
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(string $slug, array $data = [], RunTrigger $trigger = RunTrigger::Api): Run
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
        );

        // Report the run as it stands at response time. Under a real queue that
        // is still pending; under the sync driver the drive already ran.
        return $run->refresh();
    }
}
