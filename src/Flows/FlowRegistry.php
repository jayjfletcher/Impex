<?php

declare(strict_types=1);

namespace JayI\Impex\Flows;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use JayI\Impex\Exceptions\FlowCollisionException;
use JayI\Impex\Exceptions\UnknownFlowException;
use JayI\Impex\Models\FlowOverride;

/**
 * The catalogue of runnable flows.
 *
 * Flows come from two places: `impex.flows` in the application's config, and
 * runtime registration by a package's service provider. Code is the source of
 * truth for which flows exist either way; the `impex_flows` table holds only
 * runtime overrides, so a flow can be paused or rescheduled from the dashboard
 * without a deploy. A row for a slug that is not registered is inert.
 *
 * Precedence is fixed and does not depend on boot order: **the application's
 * config always wins over a package's registration**, so an application can
 * point a slug a package ships at its own subclass. Two packages claiming the
 * same slug is an error rather than a silent shadowing — that is the failure
 * mode that costs an afternoon.
 */
final class FlowRegistry
{
    /** @var array<string, class-string<Flow>> */
    private array $registered = [];

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * Register a flow under a slug.
     *
     * Safe to call from any service provider's `boot()`, in any order.
     * Registering the same class under the same slug twice is a no-op, so a
     * provider that boots more than once does no harm.
     *
     * @param  class-string<Flow>  $class
     */
    public function register(string $slug, string $class): void
    {
        if (! is_subclass_of($class, Flow::class)) {
            throw UnknownFlowException::notAFlow($class);
        }

        $existing = $this->registered[$slug] ?? null;

        if ($existing !== null && $existing !== $class) {
            throw FlowCollisionException::slug($slug, $existing, $class);
        }

        $this->registered[$slug] = $class;
    }

    /**
     * Register several flows at once.
     *
     * String keys set the slug; unkeyed entries derive one from the class.
     *
     * @param  array<int|string, class-string<Flow>>  $flows
     */
    public function registerMany(array $flows): void
    {
        foreach ($flows as $slug => $class) {
            $this->register(is_string($slug) ? $slug : $this->derive($class), $class);
        }
    }

    /**
     * Every known flow, keyed by slug, with config taking precedence.
     *
     * @return array<string, class-string<Flow>>
     */
    public function all(): array
    {
        // Merged at read time rather than memoized: a package may register
        // after the first read, and precedence must not depend on which
        // happened first.
        return array_merge($this->registered, $this->configured());
    }

    /**
     * The flows a package registered at runtime, before config overrides.
     *
     * @return array<string, class-string<Flow>>
     */
    public function registered(): array
    {
        return $this->registered;
    }

    public function has(string $slug): bool
    {
        return array_key_exists($slug, $this->all());
    }

    /**
     * The class registered under a slug.
     *
     * @return class-string<Flow>
     */
    public function class(string $slug): string
    {
        $flows = $this->all();

        if (! array_key_exists($slug, $flows)) {
            throw UnknownFlowException::slug($slug);
        }

        return $flows[$slug];
    }

    /**
     * The slug a class is registered under, deriving one if it is not.
     */
    public function slug(string $class): string
    {
        $slug = array_search($class, $this->all(), true);

        return is_string($slug) ? $slug : $this->derive($class);
    }

    /**
     * Whether the flow may currently be run.
     *
     * Defaults to enabled; only an explicit override disables it.
     */
    public function enabled(string $slug): bool
    {
        $override = $this->override($slug);

        if (! $override instanceof FlowOverride) {
            return true;
        }

        return $override->enabled ?? true;
    }

    /**
     * The runtime override for a slug, if one has been stored.
     */
    public function override(string $slug): ?FlowOverride
    {
        return FlowOverride::query()->where('slug', $slug)->first();
    }

    /**
     * The effective schedule for a slug: the override first, then config.
     */
    public function schedule(string $slug): ?string
    {
        $override = $this->override($slug);

        if (is_string($override?->schedule)) {
            return $override->schedule;
        }

        /** @var array<string, string> $schedule */
        $schedule = $this->config->get('impex.schedule', []);

        return $schedule[$slug] ?? null;
    }

    /**
     * Resolve a flow instance from the container.
     *
     * @param  class-string<Flow>|string  $class
     */
    public function make(string $class): Flow
    {
        if (! is_subclass_of($class, Flow::class)) {
            throw UnknownFlowException::notAFlow($class);
        }

        $flow = $this->container->make($class);

        if (! $flow instanceof Flow) {
            throw UnknownFlowException::notAFlow($class);
        }

        if (! method_exists($flow, 'handle')) {
            throw UnknownFlowException::notAFlow($class);
        }

        return $flow;
    }

    /**
     * The flows declared in the application's config.
     *
     * @return array<string, class-string<Flow>>
     */
    private function configured(): array
    {
        /** @var array<int|string, class-string<Flow>> $configured */
        $configured = $this->config->get('impex.flows', []);

        $flows = [];

        foreach ($configured as $slug => $class) {
            if (! is_subclass_of($class, Flow::class)) {
                throw UnknownFlowException::notAFlow($class);
            }

            $flows[is_string($slug) ? $slug : $this->derive($class)] = $class;
        }

        return $flows;
    }

    /**
     * @param  class-string<Flow>|string  $class
     */
    private function derive(string $class): string
    {
        return Str::kebab(class_basename($class));
    }
}
