<?php

declare(strict_types=1);

namespace JayI\Impex\Console\Commands;

use Illuminate\Console\Command;

class ImpexCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'impex:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package impex.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Impex placeholder command executed.');

        return self::SUCCESS;
    }
}
