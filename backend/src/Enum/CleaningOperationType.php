<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The fixed whitelist of cleaning/transformation operations. Not mapped
 * to the database — operations live inside Analysis::$operations (JSON).
 * See docs/data-cleaning.md for what each one does and its parameters.
 */
enum CleaningOperationType: string
{
    case RemoveDuplicateRows = 'remove_duplicate_rows';
    case TrimWhitespace = 'trim_whitespace';
    case NormalizeInternalWhitespace = 'normalize_internal_whitespace';
    case DropRows = 'drop_rows';
    case FillConstant = 'fill_constant';
    case FillMean = 'fill_mean';
    case FillMedian = 'fill_median';
    case FillMode = 'fill_mode';
    case ConvertToInteger = 'convert_to_integer';
    case ConvertToFloat = 'convert_to_float';
    case ConvertToDate = 'convert_to_date';
    case ConvertToDatetime = 'convert_to_datetime';
    case DropColumn = 'drop_column';
    case RemoveIqrOutliers = 'remove_iqr_outliers';

    /**
     * Whether this operation requires a "column" parameter.
     */
    public function requiresColumn(): bool
    {
        return $this !== self::RemoveDuplicateRows;
    }

    /**
     * Whether this operation requires a "value" parameter (in addition to a column).
     */
    public function requiresValue(): bool
    {
        return $this === self::FillConstant;
    }
}
