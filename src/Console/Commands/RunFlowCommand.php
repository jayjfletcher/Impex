<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JayI\Impex\Console\FlowArguments;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Impex;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RunFlowCommand extends Command
{
    protected $signature = 'impex:run
        {flow : The registered flow slug}
        {--argument=* : Arguments passed to the flow, in order}
        {--trigger=command : How this run was triggered}
        {--idempotency-key= : Return the existing run when this key has been used}';

    protected $description = 'Start an Impex flow run';

    /**
     * Register the named flow's own parameters as options before Symfony
     * validates the input.
     *
     * A command's options are normally fixed at construction, but these depend
     * on which flow was asked for — which is only known once the input exists.
     * So the slug is read straight off the raw input here, the flow's options
     * are added, and the input is re-bound against the fuller definition.
     *
     * Failures are deliberately swallowed: an unregistered slug, or a flow whose
     * class cannot be reflected, is reported properly by handle() a moment
     * later. Throwing here would replace that message with a parse error.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $slug = $this->slug($input);

        if ($slug !== null) {
            try {
                $flows = app(Impex::class)->flows();

                if ($flows->has($slug)) {
                    foreach (FlowArguments::options($flows->class($slug)) as $option) {
                        if (! $this->getDefinition()->hasOption($option->getName())) {
                            $this->getDefinition()->addOption($option);
                        }
                    }

                    $input->bind($this->getDefinition());
                }
            } catch (Throwable) {
                // handle() reports it.
            }
        }

        return parent::run($input, $output);
    }

    /**
     * The flow slug, read before the input has been validated.
     *
     * `getFirstArgument()` returns the command name, so the slug is the next
     * bare token after it. Reading it this way rather than through
     * `$this->argument()` is the point: the definition is still incomplete here,
     * so binding would fail on the very options this is about to add.
     */
    private function slug(InputInterface $input): ?string
    {
        $first = $input->getFirstArgument();

        if ($first === null) {
            return null;
        }

        // What getFirstArgument() returns depends on how the command was
        // invoked. From the terminal (ArgvInput) the command name is itself an
        // argument, so the slug is the bare token after it. Through
        // Artisan::call() or $this->artisan() (ArrayInput) the name is carried
        // separately and the slug is already the first argument.
        if ($first !== $this->getName()) {
            return $first;
        }

        $seenCommand = false;

        // __toString() quotes tokens that need it; strip that back off.
        foreach (explode(' ', (string) $input) as $token) {
            $token = trim($token, "'\"");

            if ($token === '' || str_starts_with($token, '-')) {
                continue;
            }

            if (! $seenCommand) {
                $seenCommand = $token === $first;

                continue;
            }

            return $token;
        }

        return null;
    }

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

        // The console can only carry strings, and flow files declare
        // strict_types, so a declared bool, int or float parameter would raise a
        // TypeError inside the engine before the run's first step. Read the
        // flow's own options and cast to what handle() declares while the values
        // are still here.
        try {
            $arguments = FlowArguments::from($impex->flows()->class($slug), $this->input);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

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
