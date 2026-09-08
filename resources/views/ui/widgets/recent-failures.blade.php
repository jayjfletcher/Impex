<x-atrium::card :title="__('impex::impex.widget_recent_failures')">
    @if ($runs->isEmpty())
        <p class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.no_failures') }}</p>
    @else
        <ul class="flex flex-col gap-2">
            @foreach ($runs as $run)
                <li class="flex items-center justify-between gap-3 text-sm">
                    <a class="truncate underline-offset-2 hover:underline"
                       href="{{ route('atrium.impex.runs.show', $run) }}">{{ $run->flow }}</a>
                    <span class="shrink-0 text-xs text-on-surface dark:text-on-surface-dark">
                        {{ $run->finished_at?->diffForHumans() }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-atrium::card>
