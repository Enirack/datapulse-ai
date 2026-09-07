<?php

declare(strict_types=1);

namespace App\Enum;

enum DatasetVersionKind: string
{
    case Raw = 'raw';
    case Cleaned = 'cleaned';
}
