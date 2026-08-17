<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('JayI\Impex')
    ->toUseStrictTypes();

arch('models are final')
    ->expect('JayI\Impex\Models')
    ->classes()
    ->toBeFinal();

arch('jobs carry identifiers only, never payloads')
    ->expect('JayI\Impex\Jobs')
    ->toOnlyUse([
        'Illuminate\Bus\Queueable',
        'Illuminate\Contracts\Queue\ShouldQueue',
        'Illuminate\Foundation\Bus\Dispatchable',
        'Illuminate\Queue\InteractsWithQueue',
        'Illuminate\Queue\SerializesModels',
        'JayI\Impex\Runtime\BatchRunner',
        'JayI\Impex\Runtime\Engine',
    ]);

// Parity is the default, with declared exceptions. An Action reachable over
// HTTP but not MCP is a test failure unless it is listed here with a reason.
arch('every use case is reachable from both the HTTP API and MCP')
    ->expect(fn (): array => parityGaps())
    ->toBeEmpty();
