<?php

declare(strict_types=1);

namespace QtBuilder\IO;

enum FileWriteStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
}

