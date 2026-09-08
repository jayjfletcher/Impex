<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JayI\Impex\Actions\CancelRunAction;
use JayI\Impex\Actions\ListMessagesAction;
use JayI\Impex\Actions\ListRunsAction;
use JayI\Impex\Actions\ListRunStepsAction;
use JayI\Impex\Actions\RetryRunAction;
use JayI\Impex\Actions\SignalRunAction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Models\Run;

final class RunUiController
{
    public function index(Request $request): View
    {
        // Filters are validated by the Action's own rules, so the page and the
        // JSON API accept exactly the same query.
        $filters = $request->validate(ListRunsAction::rules());

        /** @var view-string $view */
        $view = 'impex::ui.runs.index';

        return view($view, [
            'runs' => app(ListRunsAction::class)->execute($filters),
            'filters' => $filters,
            'statuses' => RunStatus::cases(),
            'triggers' => RunTrigger::cases(),
        ]);
    }

    public function show(Run $run): View
    {
        /** @var view-string $view */
        $view = 'impex::ui.runs.show';

        return view($view, [
            'run' => $run->load('owners'),
            'steps' => app(ListRunStepsAction::class)->execute($run),
            'messages' => app(ListMessagesAction::class)->execute(['run' => $run->getKey()]),
        ]);
    }

    public function cancel(Run $run): RedirectResponse
    {
        app(CancelRunAction::class)->execute($run, [
            'reason' => __('impex::impex.cancelled_from_dashboard'),
        ]);

        return redirect()
            ->route('atrium.impex.runs.show', $run)
            ->with('status', __('impex::impex.run_cancelled'));
    }

    public function retry(Run $run): RedirectResponse
    {
        app(RetryRunAction::class)->execute($run);

        return redirect()
            ->route('atrium.impex.runs.show', $run)
            ->with('status', __('impex::impex.run_retried'));
    }

    public function signal(Request $request, Run $run): RedirectResponse
    {
        $data = $request->validate(SignalRunAction::rules());

        // A payload arrives from the form as a JSON string.
        if (is_string($data['payload'] ?? null)) {
            $decoded = json_decode($data['payload'], true);

            $data['payload'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        $signal = app(SignalRunAction::class)->execute($run, $data);

        return redirect()
            ->route('atrium.impex.runs.show', $run)
            ->with('status', $signal === null
                ? __('impex::impex.signal_ignored')
                : __('impex::impex.signal_sent'));
    }
}
