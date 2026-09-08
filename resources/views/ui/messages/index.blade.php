@use(JayI\Impex\Atrium\Badges)

<x-atrium::layout :title="__('impex::impex.messages')">
    <x-atrium::page-header :title="__('impex::impex.messages')" />

    <div class="mt-5 flex flex-col gap-4">
        <x-atrium::card>
            <form method="GET" action="{{ route('atrium.impex.messages.index') }}" class="flex flex-wrap items-end gap-3">
                <x-atrium::form.select
                    name="direction"
                    :label="__('impex::impex.direction')"
                    :placeholder="__('impex::impex.all_directions')"
                    :options="collect($directions)->mapWithKeys(fn ($direction) => [$direction->value => $direction->value])"
                    :selected="$filters['direction'] ?? null"
                    wrapper="w-44" />

                <x-atrium::form.input name="channel" :label="__('impex::impex.channel')" :value="$filters['channel'] ?? null" wrapper="w-48" />

                <x-atrium::button type="submit">{{ __('impex::impex.filter') }}</x-atrium::button>
                <x-atrium::button variant="ghost" :href="route('atrium.impex.messages.index')">{{ __('impex::impex.clear') }}</x-atrium::button>
            </form>
        </x-atrium::card>

        @if ($messages->isEmpty())
            <x-atrium::empty-state :title="__('impex::impex.no_messages_at_all')" />
        @else
            <x-atrium::table striped>
                <x-slot:head>
                    <x-atrium::table.row>
                        <x-atrium::table.cell heading>{{ __('impex::impex.direction') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.channel') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.endpoint') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.status') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading numeric>{{ __('impex::impex.size') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.when') }}</x-atrium::table.cell>
                    </x-atrium::table.row>
                </x-slot:head>

                @foreach ($messages as $message)
                    <x-atrium::table.row>
                        <x-atrium::table.cell>
                            <x-atrium::badge :variant="Badges::forDirection($message->direction)">{{ $message->direction->value }}</x-atrium::badge>
                        </x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $message->channel }}</x-atrium::table.cell>
                        <x-atrium::table.cell>
                            <a class="underline-offset-2 hover:underline"
                               href="{{ route('atrium.impex.messages.show', $message) }}">{{ $message->endpoint }}</a>
                        </x-atrium::table.cell>
                        <x-atrium::table.cell>
                            @if ($message->signature_valid === false)
                                <x-atrium::badge variant="danger">{{ __('impex::impex.bad_signature') }}</x-atrium::badge>
                            @else
                                {{ $message->status_code ?? __('impex::impex.none') }}
                            @endif
                        </x-atrium::table.cell>
                        <x-atrium::table.cell numeric>{{ $message->bytes }}</x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $message->occurred_at?->diffForHumans() }}</x-atrium::table.cell>
                    </x-atrium::table.row>
                @endforeach
            </x-atrium::table>

            <x-atrium::pagination :paginator="$messages" />
        @endif
    </div>
</x-atrium::layout>
