<?php

declare(strict_types=1);

namespace JayI\Impex\Support;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Atomic locks on the configured cache store.
 *
 * Lambda shares no memory between invocations, so serialising concurrent work
 * on the same run depends entirely on this being a shared, lock-capable store.
 * A store that cannot lock is a configuration error worth failing loudly on,
 * not something to silently degrade past.
 */
final class Locks
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    public function acquire(string $key, int $seconds): Lock
    {
        return $this->provider()->lock($key, $seconds);
    }

    /**
     * The cache repository itself, for the drive-loop's dirty marker.
     */
    public function store(): Cache
    {
        return $this->cache->store($this->name());
    }

    private function provider(): LockProvider
    {
        $repository = $this->store();

        $store = $repository instanceof Repository ? $repository->getStore() : null;

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(sprintf(
                'The cache store configured for Impex [%s] does not support atomic locks, so concurrent '.
                'work on the same run cannot be serialised. Use redis, dynamodb, database, memcached, '.
                'array, or file.',
                $this->name() ?? 'default',
            ));
        }

        return $store;
    }

    private function name(): ?string
    {
        /** @var string|null $store */
        $store = $this->config->get('impex.cache.store');

        return $store;
    }
}
