<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use JayI\Impex\Exceptions\ImpexException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Base MCP request.
 *
 * Mirrors the HTTP FormRequest `persist()` pattern so tools stay one line and
 * both surfaces resolve the same Actions. Parity is then structural rather than
 * something to maintain by hand.
 */
abstract class Request extends McpRequest
{
    final public function persist(): Response|ResponseFactory
    {
        try {
            if (! $this->authorize()) {
                return Response::error('Unauthorized.');
            }

            return $this->handle($this->validated());
        } catch (ModelNotFoundException) {
            return Response::error('Not found.');
        } catch (ImpexException $e) {
            // Impex exceptions carry guidance an agent can act on, so surface
            // the message rather than a generic failure.
            return Response::error($e->getMessage());
        }
    }

    /**
     * Handle the validated tool call.
     *
     * @param  array<string, mixed>  $validated
     */
    abstract protected function handle(array $validated): Response|ResponseFactory;

    /**
     * Wrap a resolved collection in a `data` envelope.
     *
     * `Response::structured([])` throws, so an empty list must still ship
     * inside a non-empty `{ "data": [...] }` payload.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $meta
     */
    protected function structuredCollection(array $items, array $meta = []): ResponseFactory
    {
        return Response::structured(['data' => $items] + $meta);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    protected function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(): array
    {
        return Validator::validate($this->all(), $this->rules());
    }
}
