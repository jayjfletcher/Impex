<?php

declare(strict_types=1);

namespace JayI\Impex\Flows;

use JayI\Impex\Contracts\Resumable;
use JayI\Impex\Flows\Concerns\CanResume;

/**
 * Base class for work whose size is not known in advance.
 *
 * A Lambda timeout cannot be caught — the invocation is killed with no warning
 * and no shutdown hook — so a long action must decide to stop *before* the
 * ceiling rather than react to hitting it. Extend this, check shouldYield() at
 * a natural checkpoint, and return yieldTo($cursor) to be re-dispatched from
 * there. The step keeps its sequence, so the flow that scheduled it cannot tell
 * a resumed step from a slow one.
 *
 * ```php
 * final class SeedProducts extends ResumableAction
 * {
 *     public function execute(string $query): mixed
 *     {
 *         $cursor = $this->cursor();
 *
 *         foreach ($this->pages($query, $cursor) as $page) {
 *             $this->seed($page->items());
 *             $cursor = $page->lastKey();
 *
 *             if ($this->shouldYield()) {
 *                 return $this->yieldTo($cursor);
 *             }
 *         }
 *
 *         return ['seeded' => $this->total];
 *     }
 * }
 * ```
 */
abstract class ResumableAction implements Resumable
{
    use CanResume;
}
