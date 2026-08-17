<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum RunTrigger: string
{
    case Api = 'api';
    case Mcp = 'mcp';
    case Command = 'command';
    case Schedule = 'schedule';
    case Channel = 'channel';
    case Code = 'code';
    case Child = 'child';
}
