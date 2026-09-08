@use(JayI\Impex\Atrium\Badges)

<x-atrium::layout :title="__('impex::impex.runs')">
    <x-atrium::page-header :title="__('impex::impex.runs')" />

    <div class="mt-5 flex flex-col gap-4">
        @include('impex::ui.partials.status')

        {{-- Status and trigger options come from the enums themselves, so a new
             case can never drift out of the filter list. --}}
        <x-atrium::card>
            <form method="GET" action="{{ route('atrium.impex.runs.index') }}" class="flex flex-wrap items-end gap-3">
                <x-atrium::form.select
                    name="status"
                    :label="__('impex::impex.status')"
                    :placeholder="__('impex::impex.all_statuses')"
                    :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->value])"
                    :selected="$filters['status'] ?? null"
                    wrapper="w-48" />

                <x-atrium::form.select
                    name="trigger"
                    :label="__('impex::impex.trigger')"
                    :placeholder="__('impex::impex.all_triggers')"
                    :options="collect($triggers)->mapWithKeys(fn ($trigger) => [$trigger->value => $trigger->value])"
                    :selected="$filters['trigger'] ?? null"
                    wrapper="w-40" />

                <x-atrium::form.input name="flow" :label="__('impex::impex.flow')" :value="$filters['flow'] ?? null" wrapper="w-48" />

                <x-atrium::button type="submit" data-testid="filter-runs">{{ __('impex::impex.filter') }}</x-atrium::button>
                <x-atrium::button variant="ghost" :href="route('atrium.impex.runs.index')">{{ __('impex::impex.clear') }}</x-atrium::button>
            </form>
        </x-atrium::card>

        @if ($runs->isEmpty())
            <x-atrium::empty-state :title="__('impex::impex.no_runs')" />
        @else
            <x-atrium::table striped>
                <x-slot:head>
                    <x-atrium::table.row>
                        <x-atrium::table.cell heading>{{ __('impex::impex.flow') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.status') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.trigger') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.tags') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.started') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.finished') }}</x-atrium::table.cell>
                    </x-atrium::table.row>
                </x-slot:head>

                @foreach ($runs as $run)
                    <x-atrium::table.row>
                        <x-atrium::table.cell>
                            <a class="font-medium underline-offset-2 hover:underline"
                               href="{{ route('atrium.impex.runs.show', $run) }}">{{ $run->flow }}</a>
                        </x-atrium::table.cell>
                        <x-atrium::table.cell>
                            <x-atrium::badge :variant="Badges::forRun($run->status)">{{ $run->status->value }}</x-atrium::badge>
                        </x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $run->trigger->value }}</x-atrium::table.cell>
                        <x-atrium::table.cell>
                            @forelse ((array) $run->tags as $key => $value)
                                <x-atrium::badge>{{ $key }}={{ $value }}</x-atrium::badge>
                            @empty
                                <span class="opacity-60">{{ __('impex::impex.none') }}</span>
                            @endforelse
                        </x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $run->started_at?->diffForHumans() ?? __('impex::impex.none') }}</x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $run->finished_at?->diffForHumans() ?? __('impex::impex.none') }}</x-atrium::table.cell>
                    </x-atrium::table.row>
                @endforeach
            </x-atrium::table>

            <x-atrium::pagination :paginator="$runs" />
        @endif
    </div>
</x-atrium::layout>
