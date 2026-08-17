<?php

declare(strict_types=1);

namespace JayI\Impex\Actions;

use JayI\Impex\Impex;
use JayI\Impex\Models\Run;

final class CancelRunAction
{
    public function __construct(private readonly Impex $impex) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Run $run, array $data = []): Run
    {
        /** @var string|null $reason */
        $reason = $data['reason'] ?? null;

        return $this->impex->cancel($run, $reason);
    }
}
