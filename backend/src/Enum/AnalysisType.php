<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Not mapped to the database — Analysis::$type is a plain string column
 * (Phase 1 design) so future analysis kinds don't require a migration.
 * This enum just avoids magic strings in application code.
 */
enum AnalysisType: string
{
    case Profiling = 'profiling';
}
