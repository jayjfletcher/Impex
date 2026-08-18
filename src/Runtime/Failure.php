<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Throwable;

/**
 * Turns a throwable into something a database column, an API response, and an
 * operator reading the dashboard can all use.
 */
final class Failure
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(Throwable $error): array
    {
        return [
            'class' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }

    /**
     * The message recorded against a step or run, if there is one.
     *
     * @param  array<string, mixed>|null  $error
     */
    public static function message(?array $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $message = $error['message'] ?? null;

        return is_string($message) ? $message : null;
    }
}
