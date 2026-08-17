<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use JayI\Impex\Exceptions\CannotSignalTerminalRunException;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JsonException;

final class SignalCommand extends Command
{
    protected $signature = 'impex:signal
        {run : The run id}
        {name : The signal name the flow awaits}
        {--payload= : JSON payload handed to the flow when it resumes}
        {--idempotency-key= : Reusing a key will not deliver the signal twice}
        {--if-running : Treat a finished run as a no-op rather than an error}';

    protected $description = 'Deliver a signal to a run';

    public function handle(Impex $impex): int
    {
        /** @var string $runId */
        $runId = $this->argument('run');

        $run = Run::query()->find($runId);

        if (! $run instanceof Run) {
            $this->components->error(sprintf('No run found with id [%s].', $runId));

            return self::FAILURE;
        }

        try {
            $payload = $this->payload();
        } catch (JsonException $e) {
            $this->components->error('The --payload option must be valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var string $name */
        $name = $this->argument('name');

        /** @var string|null $key */
        $key = $this->option('idempotency-key');

        if ($this->option('if-running')) {
            $delivered = $impex->signalIfRunning($run, $name, $payload, $key);

            $this->components->info($delivered
                ? sprintf('Signal [%s] delivered to run [%s].', $name, $run->getKey())
                : sprintf('Run [%s] is %s; nothing delivered.', $run->getKey(), $run->status->value));

            return self::SUCCESS;
        }

        try {
            $impex->signal($run, $name, $payload, $key);
        } catch (CannotSignalTerminalRunException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Signal [%s] delivered to run [%s].', $name, $run->getKey()));

        return self::SUCCESS;
    }

    /**
     * @throws JsonException
     */
    private function payload(): mixed
    {
        $payload = $this->option('payload');

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
