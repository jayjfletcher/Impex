<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum TimerKind: string
{
    case Sleep = 'sleep';
    case SignalTimeout = 'signal_timeout';
    case RunDeadline = 'run_deadline';
    case StepDeadline = 'step_deadline';
}
