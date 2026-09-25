<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JayI\Atrium\Navigation\NavItem;
use JayI\Atrium\Plugins\PluginRegistry;
use JayI\Atrium\Widgets\WidgetDefinition;
use JayI\Atrium\Widgets\WidgetRegistry;
use JayI\Impex\Atrium\Badges;
use JayI\Impex\Atrium\ImpexPlugin;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepStatus;

it('registers itself with atrium', function (): void {
    expect(app(PluginRegistry::class)->has('impex'))->toBeTrue();
});

it('contributes navigation for every section', function (): void {
    $labels = array_map(
        fn (NavItem $item): string => $item->label,
        app(ImpexPlugin::class)->navigation(),
    );

    expect($labels)->toBe(['Runs', 'Messages', 'Flows', 'Channels']);
});

it('registers its routes inside the atrium group', function (): void {
    expect(Route::has('atrium.impex.runs.index'))->toBeTrue()
        ->and(Route::has('atrium.impex.messages.index'))->toBeTrue()
        ->and(route('atrium.impex.runs.index'))->toContain('/atrium/impex/runs');
});

it('offers widgets without placing any', function (): void {
    $keys = array_map(
        fn (WidgetDefinition $definition): string => $definition->key,
        app(ImpexPlugin::class)->widgets(),
    );

    expect($keys)->toBe(['impex.run-status', 'impex.recent-failures', 'impex.message-volume']);

    // Offered in the registry, but nothing is placed on a dashboard.
    expect(app(WidgetRegistry::class)->all())->toHaveKeys($keys);
});

it('maps every run status to a badge variant', function (): void {
    foreach (RunStatus::cases() as $status) {
        expect(Badges::forRun($status))->toBeIn(['success', 'danger', 'neutral', 'warning', 'info']);
    }
});

it('maps every step status to a badge variant', function (): void {
    foreach (StepStatus::cases() as $status) {
        expect(Badges::forStep($status))->toBeIn(['success', 'danger', 'neutral', 'info']);
    }
});

it('maps both message directions', function (): void {
    expect(Badges::forDirection(Direction::Inbound))->toBe('info')
        ->and(Badges::forDirection(Direction::Outbound))->toBe('primary');
});

it('offers a settings panel', function (): void {
    expect(app(ImpexPlugin::class)->settings()?->key)->toBe('impex');
});
