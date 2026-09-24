<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JayI\Impex\Channels\ChannelRegistry;
use JayI\Impex\Http\Controllers\ChannelController;
use JayI\Impex\Http\Controllers\ChannelIndexController;
use JayI\Impex\Http\Controllers\FlowController;
use JayI\Impex\Http\Controllers\MessageController;
use JayI\Impex\Http\Controllers\RunController;
use JayI\Impex\Http\Controllers\RunOwnerController;
use JayI\Impex\Http\Controllers\RunSignalController;
use JayI\Impex\Http\Controllers\RunStepController;

/** @var string $prefix */
$prefix = config('impex.routes.prefix');

/** @var array<int, string> $middleware */
$middleware = config('impex.routes.middleware');

/**
 * Inbound channel endpoints authenticate per request with the channel's
 * signing secret, not with an operator's session or token, so they carry their
 * own stack. Putting them behind the operator middleware would lock out the
 * very senders they exist to receive — an upstream has no user and no role.
 *
 * @var array<int, string> $channelMiddleware
 */
$channelMiddleware = config('impex.routes.channel_middleware');

Route::prefix($prefix)->middleware($middleware)->name('impex.')->group(function (): void {
    Route::get('flows', [FlowController::class, 'index'])->name('flows.index');
    Route::post('flows/{flow}/runs', [FlowController::class, 'run'])->name('flows.runs.store');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
    Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    Route::post('runs/{run}/retry', [RunController::class, 'retry'])->name('runs.retry');

    Route::get('runs/{run}/steps', [RunStepController::class, 'index'])->name('runs.steps.index');
    Route::post('runs/{run}/signals', [RunSignalController::class, 'store'])->name('runs.signals.store');

    Route::get('runs/{run}/owners', [RunOwnerController::class, 'index'])->name('runs.owners.index');
    Route::post('runs/{run}/owners', [RunOwnerController::class, 'store'])->name('runs.owners.store');
    Route::delete('runs/{run}/owners/{owner}', [RunOwnerController::class, 'destroy'])->name('runs.owners.destroy');

    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{message}', [MessageController::class, 'show'])->name('messages.show');

    // The channel listing is an operator read — what boundaries exist, not
    // traffic across one — so it stays on the operator stack above. Only the
    // receive endpoints move to the signature-authenticated group below.
    Route::get('channels', ChannelIndexController::class)->name('channels.index');
});

Route::prefix($prefix)->middleware($channelMiddleware)->name('impex.')->group(function (): void {
    // Channels with a custom path get their own named route. Everything else is
    // served by the generic endpoint below, which resolves the channel at
    // request time so adding one never depends on the route cache.
    /** @var ChannelRegistry $channels */
    $channels = app(ChannelRegistry::class);

    foreach ($channels->inbound() as $channel) {
        if ($channel->path === null) {
            continue;
        }

        Route::post($channel->path, ChannelController::class)
            ->defaults('channel', $channel->name)
            ->name('channels.'.$channel->name);
    }

    Route::post('channels/{channel}', ChannelController::class)->name('channels.receive');
});
