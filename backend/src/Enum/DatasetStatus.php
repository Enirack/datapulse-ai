<?php

declare(strict_types=1);

namespace App\Enum;

enum DatasetStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
