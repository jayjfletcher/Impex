@use(JayI\Impex\Atrium\Badges)

<x-atrium::layout :title="$message->channel">
    <x-atrium::page-header :title="$message->channel" :description="$message->endpoint">
        <x-slot:actions>
            <x-atrium::badge :variant="Badges::forDirection($message->direction)">{{ $message->direction->value }}</x-atrium::badge>
        </x-slot:actions>
    </x-atrium::page-header>

    <div class="mt-5 flex flex-col gap-5">
        <x-atrium::card>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.transport') }}</dt>
                    <dd class="text-sm">{{ $message->transport }}{{ $message->method ? ' · '.$message->method : '' }}</dd>
                </div>

                @if ($message->status_code)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.status') }}</dt>
                        <dd class="text-sm">{{ $message->status_code }}</dd>
                    </div>
                @endif

                @if ($message->duration_ms !== null)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.duration') }}</dt>
                        <dd class="text-sm">{{ __('impex::impex.milliseconds', ['count' => $message->duration_ms]) }}</dd>
                    </div>
                @endif

                @if ($message->signature_valid !== null)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.signature') }}</dt>
                        <dd>
                            <x-atrium::badge :variant="$message->signature_valid ? 'success' : 'danger'">
                                {{ $message->signature_valid ? __('impex::impex.verified') : __('impex::impex.bad_signature') }}
                            </x-atrium::badge>
                        </dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.size') }}</dt>
                    <dd class="text-sm">{{ __('impex::impex.bytes', ['count' => $message->bytes]) }}</dd>
                </div>

                @if ($message->run_id)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.run') }}</dt>
                        <dd class="text-sm">
                            <a class="underline-offset-2 hover:underline"
                               href="{{ route('atrium.impex.runs.show', $message->run_id) }}">{{ $message->run_id }}</a>
                        </dd>
                    </div>
                @endif
            </dl>
        </x-atrium::card>

        @if ($message->headers)
            <x-atrium::card :title="__('impex::impex.headers')">
                <pre class="overflow-x-auto rounded-radius bg-surface-alt p-3 text-xs dark:bg-surface-dark-alt">{{ json_encode($message->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </x-atrium::card>
        @endif

        <x-atrium::card :title="__('impex::impex.body')">
            <pre class="overflow-x-auto rounded-radius bg-surface-alt p-3 text-xs dark:bg-surface-dark-alt">{{ $message->body_preview ?? __('impex::impex.empty_body') }}</pre>

            @if ($message->body_artifact_id)
                <p class="mt-2 text-sm text-on-surface dark:text-on-surface-dark">
                    {{ __('impex::impex.body_on_disk', ['id' => $message->body_artifact_id]) }}
                </p>
            @endif
        </x-atrium::card>
    </div>
</x-atrium::layout>
