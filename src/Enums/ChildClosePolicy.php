<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum ChildClosePolicy: string
{
    /**
     * Leave running children alone when the parent finishes.
     */
    case Abandon = 'abandon';

    /**
     * Cancel running children when the parent finishes.
     */
    case Cancel = 'cancel';

    /**
     * The parent waits for the child, and a failed child fails the parent.
     * This is the default: a child you did not wait for is usually a bug.
     */
    case Fail = 'fail';
}
