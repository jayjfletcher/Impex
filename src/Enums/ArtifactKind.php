<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum ArtifactKind: string
{
    case Payload = 'payload';
    case Result = 'result';
    case Image = 'image';
    case Document = 'document';
    case Other = 'other';
}
