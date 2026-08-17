<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;

final class SignalRunAction
{
    public function __construct(private readonly Impex $impex) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'payload' => ['sometimes', 'nullable'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
            'if_running' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Deliver the signal.
     *
     * Returns null when `if_running` was set and the run had already finished —
     * a no-op the caller asked for, rather than a conflict.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Run $run, array $data): ?Signal
    {
        /** @var string $name */
        $name = $data['name'];

        /** @var string|null $key */
        $key = $data['idempotency_key'] ?? null;

        if (($data['if_running'] ?? false) === true) {
            return $this->impex->signalIfRunning($run, $name, $data['payload'] ?? null, $key)
                ? $run->signals()->where('name', $name)->latest('delivered_at')->first()
                : null;
        }

        return $this->impex->signal($run, $name, $data['payload'] ?? null, $key);
    }
}
