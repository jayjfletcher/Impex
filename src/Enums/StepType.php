<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum StepType: string
{
    case Action = 'action';
    case Compensation = 'compensation';
    case SideEffect = 'side_effect';
    case Signal = 'signal';
    case FanOut = 'fan_out';
    case Batch = 'batch';
    case Child = 'child';
    case Timer = 'timer';
}
