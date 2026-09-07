<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportFormat: string
{
    case Pdf = 'pdf';
    case Html = 'html';
    case Json = 'json';
}
