<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp\Requests;

use JayI\Impex\Actions\SignalRunAction;
use JayI\Impex\Http\Resources\SignalResource;
use JayI\Impex\Models\Signal;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class SignalRunMcpRequest extends RunRequest
{
    protected function authorize(): bool
    {
        return $this->allows('create', Signal::class, [$this->run()]);
    }

    protected function rules(): array
    {
        return SignalRunAction::rules() + [
            'run' => ['required', 'string', 'max:26'],
        ];
    }

    protected function handle(array $validated): ResponseFactory
    {
        $signal = app(SignalRunAction::class)->execute($this->run(), $validated);

        if (! $signal instanceof Signal) {
            return Response::structured(['delivered' => false, 'reason' => 'The run has finished.']);
        }

        return Response::structured((new SignalResource($signal))->resolve() + ['delivered' => true]);
    }
}
