<div class="flex flex-col gap-4">
    <x-atrium::card :title="__('impex::impex.retention')">
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($retention as $key => $days)
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ str($key)->headline() }}</dt>
                    <dd class="text-sm">{{ __('impex::impex.days', ['count' => $days]) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-atrium::card>

    <x-atrium::card :title="__('impex::impex.limits')">
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($limits as $key => $value)
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ str($key)->headline() }}</dt>
                    <dd class="text-sm tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-atrium::card>

    <x-atrium::card :title="__('impex::impex.artifacts')">
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($artifacts as $key => $value)
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ str($key)->headline() }}</dt>
                    <dd class="text-sm">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                </div>
            @endforeach

            <div>
                <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.preview_bytes') }}</dt>
                <dd class="text-sm">{{ __('impex::impex.bytes', ['count' => $previewBytes]) }}</dd>
            </div>
        </dl>
    </x-atrium::card>
</div>
