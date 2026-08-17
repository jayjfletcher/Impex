<?php

declare(strict_types=1);

namespace JayI\Impex\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Models\Run;

/**
 * @extends Factory<Run>
 */
final class RunFactory extends Factory
{
    protected $model = Run::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flow' => 'example',
            'flow_class' => 'App\\Flows\\ExampleFlow',
            'status' => RunStatus::Pending,
            'trigger' => RunTrigger::Code,
            'input' => ['value' => []],
        ];
    }
}
