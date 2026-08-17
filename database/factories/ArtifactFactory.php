<?php

declare(strict_types=1);

namespace JayI\Impex\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Models\Artifact;

/**
 * @extends Factory<Artifact>
 */
final class ArtifactFactory extends Factory
{
    protected $model = Artifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'local',
            'path' => 'impex/payload/example.json',
            'kind' => ArtifactKind::Payload,
            'mime' => 'application/json',
            'bytes' => 2,
        ];
    }
}
