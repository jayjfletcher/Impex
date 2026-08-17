<?php

declare(strict_types=1);

namespace JayI\Impex\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Models\Message;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => Direction::Inbound,
            'channel' => 'example',
            'transport' => 'http',
            'endpoint' => 'https://example.test/webhook',
            'method' => 'POST',
            'bytes' => 0,
            'occurred_at' => Carbon::now(),
        ];
    }
}
