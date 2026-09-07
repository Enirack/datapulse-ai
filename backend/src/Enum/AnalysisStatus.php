<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
