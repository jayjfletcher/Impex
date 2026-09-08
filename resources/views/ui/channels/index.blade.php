<x-atrium::layout :title="__('impex::impex.channels')">
    <x-atrium::page-header :title="__('impex::impex.channels')" />

    <div class="mt-5">
        @if ($channels === [])
            <x-atrium::empty-state :title="__('impex::impex.no_channels')" />
        @else
            <x-atrium::table striped>
                <x-slot:head>
                    <x-atrium::table.row>
                        <x-atrium::table.cell heading>{{ __('impex::impex.name') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.path') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.flow') }}</x-atrium::table.cell>
                        <x-atrium::table.cell heading>{{ __('impex::impex.signature') }}</x-atrium::table.cell>
                    </x-atrium::table.row>
                </x-slot:head>

                @foreach ($channels as $channel)
                    <x-atrium::table.row>
                        <x-atrium::table.cell><code class="text-xs">{{ $channel['name'] }}</code></x-atrium::table.cell>
                        <x-atrium::table.cell><code class="text-xs">{{ $channel['path'] ?? 'channels/'.$channel['name'] }}</code></x-atrium::table.cell>
                        <x-atrium::table.cell>{{ $channel['flow'] ?? __('impex::impex.none') }}</x-atrium::table.cell>
                        <x-atrium::table.cell>
                            <x-atrium::badge :variant="($channel['signed'] ?? false) ? 'success' : 'neutral'">
                                {{ ($channel['signed'] ?? false) ? __('impex::impex.verified') : __('impex::impex.unsigned') }}
                            </x-atrium::badge>
                        </x-atrium::table.cell>
                    </x-atrium::table.row>
                @endforeach
            </x-atrium::table>
        @endif
    </div>
</x-atrium::layout>
