<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;
use JayI\Impex\Models\Artifact;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;

/**
 * Prunes Impex history in dependency order.
 *
 * Runs and messages first, then artifacts — an artifact must never be removed
 * while a row still points at it, or a failed run loses the payloads you would
 * open it to read.
 */
final class PruneCommand extends Command
{
    protected $signature = 'impex:prune';

    protected $description = 'Prune expired Impex runs, messages, and artifacts';

    public function handle(): int
    {
        $runs = (new Run)->pruneAll();
        $messages = (new Message)->pruneAll();
        $artifacts = (new Artifact)->pruneAll();

        $this->components->info(sprintf(
            'Pruned %d run(s), %d message(s), and %d artifact(s).',
            $runs,
            $messages,
            $artifacts,
        ));

        return self::SUCCESS;
    }
}
