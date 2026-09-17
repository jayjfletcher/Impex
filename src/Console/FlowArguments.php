<?php

declare(strict_types=1);

namespace JayI\Impex\Console;

use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Exposes a flow's `handle()` parameters as real console options, and casts what
 * the console sends back to the types that signature declares.
 *
 * Two problems, one seam.
 *
 * The first is typing. Every console value arrives as a string, because that is
 * all a command line carries, and flow files declare `strict_types=1`, so PHP
 * will not coerce at the call boundary. A flow with `handle(bool $initial)`
 * reached with the string `"1"` raises
 * `Argument #3 ($initial) must be of type bool, string given` inside the engine,
 * and the run fails before its first step — which made `$initial`, and so every
 * backfill, unreachable from the command line.
 *
 * The second is ergonomics. Passing arguments by position means padding the
 * ones you do not care about (`--argument= --argument= --argument=1`) and
 * silently breaking every saved command the moment a parameter is inserted.
 * Reflecting the signature into named options removes both: `--initial` says
 * what it means, and its position stops mattering.
 *
 * Casting here rather than in the engine is deliberate. The console is the one
 * caller that legitimately holds strings, so its strings stop here; the stored
 * payload, the replay history and the engine's spread into `handle()` then all
 * carry the declared types, and a genuine type error from an in-process caller
 * still surfaces as one.
 */
final class FlowArguments
{
    /**
     * The options a flow's parameters become.
     *
     * A `bool` parameter becomes a flag taking no value (`--initial`), because
     * that is what a boolean reads like on a command line. Everything else takes
     * one (`--since=2026-01-01`). Variadics are skipped: they have no single
     * name to bind to, and positional arguments still reach them.
     *
     * @param  class-string  $flow
     * @return array<int, InputOption>
     */
    public static function options(string $flow): array
    {
        $options = [];

        foreach (self::parameters($flow) as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();
            $isFlag = $type instanceof ReflectionNamedType
                && $type->getName() === 'bool'
                && ! $type->allowsNull();

            $options[] = new InputOption(
                name: self::optionName($parameter->getName()),
                mode: $isFlag ? InputOption::VALUE_NONE : InputOption::VALUE_OPTIONAL,
                description: sprintf('The flow\'s $%s parameter', $parameter->getName()),
            );
        }

        return $options;
    }

    /**
     * The arguments to invoke a flow with, read from the console input.
     *
     * Named options win over positional `--argument` values, so the two can be
     * mixed and the explicit name is the one that counts. Named values are
     * returned under their parameter names, which PHP's spread applies as named
     * arguments — so an untouched parameter keeps its declared default rather
     * than being padded with a null that means something different.
     *
     * @param  class-string  $flow
     * @return array<int|string, mixed>
     */
    public static function from(string $flow, InputInterface $input): array
    {
        /** @var array<int, string> $positional */
        $positional = (array) $input->getOption('argument');

        $arguments = self::positional($flow, $positional);

        foreach (self::parameters($flow) as $position => $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $option = self::optionName($parameter->getName());

            if (! $input->hasOption($option)) {
                continue;
            }

            $value = $input->getOption($option);

            // VALUE_NONE reads false when absent, which is a flag's default, not
            // an instruction. Only a flag that was actually passed should speak.
            if ($value === false || $value === null) {
                continue;
            }

            // PHP rejects a named argument that overwrites a positional one, so
            // the positional slot is dropped rather than both being sent. The
            // explicit name is the one that counts.
            unset($arguments[$position]);

            $arguments[$parameter->getName()] = $value === true
                ? true
                : self::value((string) $value, $parameter->getType(), $parameter->getName());
        }

        // Dropping a slot can leave a hole mid-list, and a gap would shift every
        // later positional argument onto the wrong parameter. Name what is left
        // so position stops mattering.
        return self::nameRemaining($flow, $arguments);
    }

    /**
     * Convert any still-positional entry to its parameter's name.
     *
     * Only needed when a named option displaced a positional one and left a gap;
     * a contiguous list is returned untouched, so the ordinary positional call
     * keeps spreading exactly as it always did.
     *
     * @param  class-string  $flow
     * @param  array<int|string, mixed>  $arguments
     * @return array<int|string, mixed>
     */
    private static function nameRemaining(string $flow, array $arguments): array
    {
        $positions = array_filter(array_keys($arguments), 'is_int');

        if ($positions === [] || $positions === range(0, count($positions) - 1)) {
            return $arguments;
        }

        $parameters = self::parameters($flow);
        $named = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;

                continue;
            }

            $parameter = $parameters[$key] ?? null;

            if ($parameter === null) {
                // Beyond the declared parameters: nothing to name it after, and
                // a variadic still takes it positionally.
                $named[] = $value;

                continue;
            }

            $named[$parameter->getName()] = $value;
        }

        return $named;
    }

    /**
     * Positional `--argument` values, cast to their declared types.
     *
     * Kept working because it is the older spelling and scripts may rely on it.
     *
     * @param  class-string  $flow
     * @param  array<int, string>  $arguments
     * @return array<int, mixed>
     */
    private static function positional(string $flow, array $arguments): array
    {
        $parameters = self::parameters($flow);

        $cast = [];

        foreach (array_values($arguments) as $position => $argument) {
            $parameter = $parameters[$position] ?? null;

            $cast[] = $parameter === null
                ? $argument
                : self::value($argument, $parameter->getType(), $parameter->getName());
        }

        return $cast;
    }

    /**
     * @param  class-string  $flow
     * @return array<int, ReflectionParameter>
     */
    private static function parameters(string $flow): array
    {
        if (! method_exists($flow, 'handle')) {
            return [];
        }

        return (new ReflectionMethod($flow, 'handle'))->getParameters();
    }

    /**
     * `$batchSize` becomes `--batch-size`, matching how console options read
     * everywhere else.
     */
    private static function optionName(string $parameter): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter));
    }

    /**
     * One value, cast to its declared type.
     *
     * A union is left alone: `array|string|null $ids` already accepts the string
     * the console sent, and picking a member for it would be guesswork.
     */
    private static function value(string $argument, ?ReflectionType $type, string $name): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            return $argument === '' && $type->allowsNull() ? null : $argument;
        }

        if (! $type instanceof ReflectionNamedType) {
            return $argument;
        }

        // An omitted positional argument is written as an empty `--argument=`,
        // which means "leave this one at its default" — so a nullable parameter
        // takes null rather than the empty string.
        if ($argument === '' && $type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'bool' => self::bool($argument, $name),
            'int' => self::int($argument, $name),
            'float' => self::float($argument, $name),
            default => $argument,
        };
    }

    /**
     * `1`, `true`, `yes` and `on` are true; `0`, `false`, `no`, `off` and an
     * empty string are false. Anything else is a mistake worth reporting, not a
     * silent false that would turn a requested full sweep into an incremental
     * one.
     */
    private static function bool(string $argument, string $name): bool
    {
        if ($argument === '') {
            return false;
        }

        $value = filter_var($argument, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'Argument [%s] expects a boolean, but [%s] was given. Use 1 or 0.',
                $name,
                $argument,
            ));
        }

        return $value;
    }

    /**
     * A non-numeric string would otherwise cast to 0, which for a batch size or
     * a limit is a silently empty run.
     */
    private static function int(string $argument, string $name): int
    {
        $value = filter_var($argument, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new InvalidArgumentException(sprintf(
                'Argument [%s] expects an integer, but [%s] was given.',
                $name,
                $argument,
            ));
        }

        return $value;
    }

    private static function float(string $argument, string $name): float
    {
        $value = filter_var($argument, FILTER_VALIDATE_FLOAT);

        if ($value === false) {
            throw new InvalidArgumentException(sprintf(
                'Argument [%s] expects a number, but [%s] was given.',
                $name,
                $argument,
            ));
        }

        return $value;
    }
}
