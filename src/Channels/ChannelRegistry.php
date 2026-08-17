<?php

declare(strict_types=1);

namespace JayI\Impex\Channels;

use Illuminate\Contracts\Config\Repository as Config;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Exceptions\UnknownChannelException;

/**
 * The catalogue of named boundary configurations.
 */
final class ChannelRegistry
{
    public function __construct(private readonly Config $config) {}

    /**
     * Read straight from config on every call.
     *
     * Memoizing this would freeze the catalogue at the moment the container
     * first resolved the registry — which for a package registering routes at
     * boot is before the application has finished configuring itself.
     *
     * @return array<string, ChannelConfig>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = $this->config->get('impex.channels', []);

        $channels = [];

        foreach ($configured as $name => $channel) {
            $channels[$name] = ChannelConfig::fromArray((string) $name, $channel);
        }

        return $channels;
    }

    /**
     * @return array<string, ChannelConfig>
     */
    public function inbound(): array
    {
        return array_filter($this->all(), fn (ChannelConfig $c): bool => $c->direction === Direction::Inbound);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function get(string $name): ChannelConfig
    {
        $channels = $this->all();

        if (! array_key_exists($name, $channels)) {
            throw UnknownChannelException::name($name);
        }

        return $channels[$name];
    }
}
