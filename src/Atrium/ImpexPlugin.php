<?php

declare(strict_types=1);

namespace JayI\Impex\Atrium;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use JayI\Atrium\Navigation\NavItem;
use JayI\Atrium\Plugins\Plugin;
use JayI\Atrium\Search\SearchResult;
use JayI\Atrium\Search\SearchSource;
use JayI\Atrium\Settings\SettingsPanel;
use JayI\Atrium\Widgets\WidgetDefinition;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Http\Ui\ChannelUiController;
use JayI\Impex\Http\Ui\FlowUiController;
use JayI\Impex\Http\Ui\MessageUiController;
use JayI\Impex\Http\Ui\RunUiController;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;

/**
 * Registers Impex inside the Atrium dashboard.
 *
 * Widgets declared here are offered in Atrium's picker. None is ever placed
 * on a dashboard automatically; that is always a user's choice.
 */
class ImpexPlugin extends Plugin
{
    public function key(): string
    {
        return 'impex';
    }

    public function label(): string
    {
        return 'Impex';
    }

    public function navigation(): array
    {
        return [
            NavItem::make(__('impex::impex.runs'))
                ->route('atrium.impex.runs.index')
                ->group('Impex')
                ->sort(10)
                ->badge(fn (): ?int => Run::query()->active()->count() ?: null),

            NavItem::make(__('impex::impex.messages'))->route('atrium.impex.messages.index')->group('Impex')->sort(20),
            NavItem::make(__('impex::impex.flows'))->route('atrium.impex.flows.index')->group('Impex')->sort(30),
            NavItem::make(__('impex::impex.channels'))->route('atrium.impex.channels.index')->group('Impex')->sort(40),
        ];
    }

    public function routes(): void
    {
        Route::name('impex.')->group(function (): void {
            Route::get('impex/runs', [RunUiController::class, 'index'])->name('runs.index');
            Route::get('impex/runs/{run}', [RunUiController::class, 'show'])->name('runs.show');
            Route::post('impex/runs/{run}/cancel', [RunUiController::class, 'cancel'])->name('runs.cancel');
            Route::post('impex/runs/{run}/retry', [RunUiController::class, 'retry'])->name('runs.retry');
            Route::post('impex/runs/{run}/signals', [RunUiController::class, 'signal'])->name('runs.signal');

            Route::get('impex/messages', [MessageUiController::class, 'index'])->name('messages.index');
            Route::get('impex/messages/{message}', [MessageUiController::class, 'show'])->name('messages.show');

            Route::get('impex/flows', [FlowUiController::class, 'index'])->name('flows.index');
            Route::post('impex/flows/{flow}/runs', [FlowUiController::class, 'run'])->name('flows.run');

            Route::get('impex/channels', ChannelUiController::class)->name('channels.index');
        });
    }

    /**
     * Widget types Impex makes available.
     *
     * Returning a definition offers the widget in the picker; it does not
     * place it on anyone's dashboard.
     */
    public function widgets(): array
    {
        return [
            WidgetDefinition::make('impex.run-status')
                ->label(__('impex::impex.widget_run_status'))
                ->description(__('impex::impex.widget_run_status_description'))
                ->defaultSize(6, 2)
                ->view('impex::ui.widgets.run-status')
                ->resolve(fn (): array => [
                    'counts' => collect(RunStatus::cases())
                        ->mapWithKeys(fn (RunStatus $status): array => [
                            $status->value => Run::query()->where('status', $status)->count(),
                        ])
                        ->all(),
                ]),

            WidgetDefinition::make('impex.recent-failures')
                ->label(__('impex::impex.widget_recent_failures'))
                ->description(__('impex::impex.widget_recent_failures_description'))
                ->defaultSize(6, 2)
                ->view('impex::ui.widgets.recent-failures')
                ->resolve(fn (): array => [
                    'runs' => Run::query()
                        ->where('status', RunStatus::Failed)
                        ->latest('finished_at')
                        ->limit(5)
                        ->get(),
                ]),

            WidgetDefinition::make('impex.message-volume')
                ->label(__('impex::impex.widget_messages'))
                ->description(__('impex::impex.widget_messages_description'))
                ->defaultSize(3, 1)
                ->view('impex::ui.widgets.message-volume')
                ->resolve(fn (): array => [
                    'inbound' => Message::query()
                        ->where('direction', Direction::Inbound)
                        ->where('occurred_at', '>=', now()->subDay())
                        ->count(),
                    'outbound' => Message::query()
                        ->where('direction', Direction::Outbound)
                        ->where('occurred_at', '>=', now()->subDay())
                        ->count(),
                ]),
        ];
    }

    public function settings(): ?SettingsPanel
    {
        return SettingsPanel::make('impex')
            ->label(__('impex::impex.settings_label'))
            ->description(__('impex::impex.settings_description'))
            ->view('impex::ui.settings')
            ->resolve(fn (): array => [
                'retention' => (array) config('impex.retention', []),
                'limits' => (array) config('impex.limits', []),
                'artifacts' => (array) config('impex.artifacts', []),
                'previewBytes' => (int) config('impex.messages.preview_bytes', 0),
            ]);
    }

    public function search(): ?SearchSource
    {
        return SearchSource::make('impex')
            ->label(__('impex::impex.label'))
            ->using(fn (string $query): array => Run::query()
                ->where(fn (Builder $builder): Builder => $builder
                    ->where('flow', 'like', '%'.$query.'%')
                    ->orWhere('id', 'like', $query.'%'))
                ->latest('created_at')
                ->limit(5)
                ->get()
                ->map(fn (Run $run): SearchResult => SearchResult::make(
                    $run->flow,
                    route('atrium.impex.runs.show', $run),
                )->subtitle($run->status->value)->group(__('impex::impex.runs')))
                ->all());
    }
}
