<?php

declare(strict_types=1);

namespace JayI\Impex\Http;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base HTTP request.
 *
 * Validation rules come from the Action the request wraps, and `persist()`
 * calls that same Action. The MCP surface does the same, so both speak to one
 * implementation rather than two that drift.
 */
abstract class Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
}
