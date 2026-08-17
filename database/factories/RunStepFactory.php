<?php

declare(strict_types=1);

namespace JayI\Impex\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

/**
 * @extends Factory<RunStep>
 */
final class RunStepFactory extends Factory
{
    protected $model = RunStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'phase' => StepPhase::Forward,
            'sequence' => 0,
            'type' => StepType::Action,
            'name' => 'App\\Flows\\Actions\\Example',
            'status' => StepStatus::Pending,
            'attempts' => 0,
            'max_attempts' => 1,
        ];
    }
}
