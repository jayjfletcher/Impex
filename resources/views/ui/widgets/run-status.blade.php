@use(JayI\Impex\Atrium\Badges)
@use(JayI\Impex\Enums\RunStatus)

<x-atrium::card :title="__('impex::impex.widget_run_status')">
    <div class="flex flex-wrap gap-2">
        @foreach ($counts as $status => $count)
            <a class="flex items-center gap-2 rounded-radius border border-outline px-3 py-2 transition hover:bg-surface-alt dark:border-outline-dark dark:hover:bg-surface-dark-alt"
               href="{{ route('atrium.impex.runs.index', ['status' => $status]) }}">
                <x-atrium::badge :variant="Badges::forRun(RunStatus::from($status))">{{ $status }}</x-atrium::badge>
                <span class="text-sm font-semibold tabular-nums">{{ $count }}</span>
            </a>
        @endforeach
    </div>
</x-atrium::card>
