<?php

declare(strict_types=1);

namespace JayI\Impex\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use JayI\Impex\Access\Authorizer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base HTTP request.
 *
 * Validation rules come from the Action the request wraps, and `persist()`
 * calls that same Action. The MCP surface does the same, so both speak to one
 * implementation rather than two that drift.
 *
 * With `impex.authorization` on, every request acts as the authenticated user
 * and each call is checked against the policies in `impex.policies`.
 */
abstract class Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->authorizer()->authenticated($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Execute the request's use case and build the response.
     */
    abstract public function persist(): Response;

    protected function authorizer(): Authorizer
    {
        return app(Authorizer::class);
    }

    /**
     * The user calls act as, or null when authorization is off.
     */
    protected function actor(): ?Model
    {
        return $this->authorizer()->actor($this->user());
    }

    /**
     * Check an ability against the model's policy from `impex.policies`.
     *
     * @param  Model|class-string<Model>  $subject
     * @param  array<int, mixed>  $arguments
     */
    protected function allows(string $ability, Model|string $subject, array $arguments = []): bool
    {
        return $this->authorizer()->can($this->user(), $ability, $subject, $arguments);
    }

    /**
     * Check an ability against every model given; an empty list passes.
     *
     * @param  iterable<int, Model>  $models
     */
    protected function allowsEach(string $ability, iterable $models): bool
    {
        foreach ($models as $model) {
            if (! $this->allows($ability, $model)) {
                return false;
            }
        }

        return true;
    }
}
