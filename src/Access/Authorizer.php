<?php

declare(strict_types=1);

namespace JayI\Impex\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

/**
 * How the JSON API and MCP tools decide whether the caller may act.
 *
 * With `impex.authorization` on, every call acts as the authenticated user:
 * they only see the runs they own, they own the runs they start, and each
 * call goes through the Gate, so the policies in `impex.policies` apply. With
 * it off — the default — the route middleware is the only check, as suits an
 * operator API or a trusted server-to-server integration.
 */
final class Authorizer
{
    public function __construct(
        private readonly Config $config,
        private readonly Gate $gate,
    ) {}

    public function enabled(): bool
    {
        return $this->config->get('impex.authorization', false) === true;
    }

    /**
     * Whether the caller may use the surface at all.
     */
    public function authenticated(?Authenticatable $user): bool
    {
        return ! $this->enabled() || $user instanceof Model;
    }

    /**
     * The model calls act as, or null when authorization is off.
     */
    public function actor(?Authenticatable $user): ?Model
    {
        return $this->enabled() && $user instanceof Model ? $user : null;
    }

    /**
     * Whether the user may perform an ability on a model, or on a model class
     * for abilities such as `viewAny` and `create`.
     *
     * @param  Model|class-string<Model>  $subject
     * @param  array<int, mixed>  $arguments
     */
    public function can(?Authenticatable $user, string $ability, Model|string $subject, array $arguments = []): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $user instanceof Model && $this->gate->forUser($user)->allows($ability, [$subject, ...$arguments]);
    }
}
