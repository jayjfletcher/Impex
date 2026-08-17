<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Impex;

final class RunFlowCommand extends Command
{
    protected $signature = 'impex:run
        {flow : The registered flow slug}
        {--argument=* : Arguments passed to the flow, in order}
        {--trigger=command : How this run was triggered}
        {--idempotency-key= : Return the existing run when this key has been used}';

    protected $description = 'Start an Impex flow run';

    public function handle(Impex $impex): int
    {
        /** @var string $slug */
        $slug = $this->argument('flow');

        if (! $impex->flows()->has($slug)) {
            $this->components->error(sprintf('No flow is registered under [%s].', $slug));

            return self::FAILURE;
        }

        if (! $impex->flows()->enabled($slug)) {
            $this->components->warn(sprintf('The flow [%s] is disabled.', $slug));

            return self::FAILURE;
        }

        /** @var array<int, string> $arguments */
        $arguments = $this->option('argument');

        /** @var string $trigger */
        $trigger = $this->option('trigger');

        /** @var string|null $key */
        $key = $this->option('idempotency-key');

        $run = $impex->run(
            slug: $slug,
            arguments: $arguments,
            trigger: RunTrigger::tryFrom($trigger) ?? RunTrigger::Command,
            idempotencyKey: $key,
        );

        $this->components->info(sprintf('Started run [%s] for flow [%s].', $run->getKey(), $slug));

        return self::SUCCESS;
    }
}
