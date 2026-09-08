@use(JayI\Impex\Atrium\Badges)
@use(JayI\Impex\Enums\StepPhase)

<x-atrium::layout :title="$run->flow">
    <x-atrium::page-header :title="$run->flow">
        <x-slot:actions>
            <x-atrium::badge :variant="Badges::forRun($run->status)">{{ $run->status->value }}</x-atrium::badge>

            @unless ($run->status->isFinished())
                <form method="POST" action="{{ route('atrium.impex.runs.cancel', $run) }}">
                    @csrf
                    <x-atrium::button variant="outline" type="submit" data-testid="cancel-run">
                        {{ __('impex::impex.cancel') }}
                    </x-atrium::button>
                </form>
            @endunless

            <form method="POST" action="{{ route('atrium.impex.runs.retry', $run) }}">
                @csrf
                <x-atrium::button variant="outline" type="submit" data-testid="retry-run">
                    {{ __('impex::impex.retry') }}
                </x-atrium::button>
            </form>
        </x-slot:actions>
    </x-atrium::page-header>

    <div class="mt-5 flex flex-col gap-5">
        @include('impex::ui.partials.status')

        <x-atrium::card>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.run_id') }}</dt>
                    <dd class="font-mono text-sm">{{ $run->getKey() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.trigger') }}</dt>
                    <dd class="text-sm">{{ $run->trigger->value }}</dd>
                </div>

                @if ($run->idempotency_key)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.idempotency_key') }}</dt>
                        <dd class="font-mono text-sm">{{ $run->idempotency_key }}</dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.started') }}</dt>
                    <dd class="text-sm">{{ $run->started_at?->diffForHumans() ?? __('impex::impex.none') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.finished') }}</dt>
                    <dd class="text-sm">{{ $run->finished_at?->diffForHumans() ?? __('impex::impex.none') }}</dd>
                </div>

                @if ($run->parent_run_id)
                    <div>
                        <dt class="text-sm text-on-surface dark:text-on-surface-dark">{{ __('impex::impex.parent') }}</dt>
                        <dd class="text-sm">
                            <a class="underline-offset-2 hover:underline"
                               href="{{ route('atrium.impex.runs.show', $run->parent_run_id) }}">{{ $run->parent_run_id }}</a>
                        </dd>
                    </div>
                @endif
            </dl>
        </x-atrium::card>

        @if ($run->error)
            <x-atrium::alert variant="danger" :title="$run->error['class'] ?? __('impex::impex.status')">
                {{ $run->error['message'] ?? '' }}
            </x-atrium::alert>
        @endif

        @if ($run->status === \JayI\Impex\Enums\RunStatus::Waiting)
            <x-atrium::card :title="__('impex::impex.send_signal')">
                <form method="POST" action="{{ route('atrium.impex.runs.signal', $run) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-atrium::form.input name="name" :label="__('impex::impex.signal_name')" required class="w-56" />
                    <x-atrium::form.input name="payload" :label="__('impex::impex.signal_payload')" class="w-72" />
                    <x-atrium::button type="submit" data-testid="send-signal">{{ __('impex::impex.send') }}</x-atrium::button>
                </form>
            </x-atrium::card>
        @endif

        <x-atrium::card :title="__('impex::impex.steps')">
            @if ($steps->isEmpty())
                <x-atrium::empty-state :title="__('impex::impex.no_steps')" />
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($steps as $step)
                        @php($isRollback = $step->phase === StepPhase::Rollback)

                        <li @class([
                            'rounded-radius border p-3',
                            'border-outline dark:border-outline-dark' => ! $isRollback,
                            'border-dashed border-warning' => $isRollback,
                        ])>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm">{{ $step->name }}</span>

                                <x-atrium::badge :variant="Badges::forStep($step->status)">{{ $step->status->value }}</x-atrium::badge>

                                @if ($isRollback)
                                    <x-atrium::badge variant="warning">{{ __('impex::impex.rollback') }}</x-atrium::badge>
                                @endif

                                @if ($step->undone && $step->status !== \JayI\Impex\Enums\StepStatus::Undone)
                                    <x-atrium::badge>{{ __('impex::impex.undone') }}</x-atrium::badge>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-on-surface dark:text-on-surface-dark">
                                #{{ $step->sequence }} &middot; {{ $step->type->value }}
                                @if ($step->attempts > 1)
                                    &middot; {{ __('impex::impex.attempts', ['count' => $step->attempts]) }}
                                @endif
                                @if ($step->resumptions > 0)
                                    &middot; {{ __('impex::impex.resumed', ['count' => $step->resumptions]) }}
                                @endif
                                @if ($step->result_artifact_id)
                                    &middot; {{ __('impex::impex.result_on_disk') }}
                                @endif
                            </p>

                            @if ($step->error)
                                <p class="mt-1 text-xs text-danger">{{ $step->error['message'] ?? '' }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-atrium::card>

        <x-atrium::card :title="__('impex::impex.owners')">
            @if ($run->owners->isEmpty())
                <x-atrium::empty-state :title="__('impex::impex.no_owners')" />
            @else
                <x-atrium::table compact>
                    <x-slot:head>
                        <x-atrium::table.row>
                            <x-atrium::table.cell heading>{{ __('impex::impex.role') }}</x-atrium::table.cell>
                            <x-atrium::table.cell heading>{{ __('impex::impex.type') }}</x-atrium::table.cell>
                            <x-atrium::table.cell heading>{{ __('impex::impex.id') }}</x-atrium::table.cell>
                        </x-atrium::table.row>
                    </x-slot:head>

                    @foreach ($run->owners as $owner)
                        <x-atrium::table.row>
                            <x-atrium::table.cell>{{ $owner->role }}</x-atrium::table.cell>
                            <x-atrium::table.cell class="font-mono text-xs">{{ $owner->owner_type }}</x-atrium::table.cell>
                            <x-atrium::table.cell class="font-mono text-xs">{{ $owner->owner_id }}</x-atrium::table.cell>
                        </x-atrium::table.row>
                    @endforeach
                </x-atrium::table>
            @endif
        </x-atrium::card>

        <x-atrium::card :title="__('impex::impex.messages')">
            @if ($messages->isEmpty())
                <x-atrium::empty-state :title="__('impex::impex.no_messages')" />
            @else
                <x-atrium::table compact>
                    <x-slot:head>
                        <x-atrium::table.row>
                            <x-atrium::table.cell heading>{{ __('impex::impex.direction') }}</x-atrium::table.cell>
                            <x-atrium::table.cell heading>{{ __('impex::impex.channel') }}</x-atrium::table.cell>
                            <x-atrium::table.cell heading>{{ __('impex::impex.endpoint') }}</x-atrium::table.cell>
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
                            <x-atrium::table.cell>{{ $message->occurred_at?->diffForHumans() }}</x-atrium::table.cell>
                        </x-atrium::table.row>
                    @endforeach
                </x-atrium::table>
            @endif
        </x-atrium::card>
    </div>
</x-atrium::layout>
