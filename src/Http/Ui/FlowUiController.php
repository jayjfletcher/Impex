<?php

declare(strict_types=1);

namespace JayI\Impex\Http\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JayI\Impex\Actions\ListFlowsAction;
use JayI\Impex\Actions\RunFlowAction;

final class FlowUiController
{
    public function index(): View
    {
        /** @var view-string $view */
        $view = 'impex::ui.flows.index';

        return view($view, ['flows' => app(ListFlowsAction::class)->execute()]);
    }

    public function run(Request $request, string $flow): RedirectResponse
    {
        $validated = $request->validate([
            'arguments' => ['nullable', 'string'],
        ]);

        $arguments = [];

        if (($validated['arguments'] ?? '') !== '') {
            $decoded = json_decode((string) $validated['arguments'], true);

            if (! is_array($decoded)) {
                return back()->withErrors(['arguments' => __('impex::impex.invalid_arguments')]);
            }

            $arguments = $decoded;
        }

        $run = app(RunFlowAction::class)->execute($flow, ['arguments' => $arguments]);

        return redirect()
            ->route('atrium.impex.runs.show', $run)
            ->with('status', __('impex::impex.flow_started'));
    }
}
