<?php

declare(strict_types=1);

namespace App\Cleaning;

use App\Enum\CleaningOperationType;

/**
 * One validated, structurally-sound step of a cleaning pipeline.
 * Structural validity only — whether `column` actually exists in the
 * dataset, and whether its type is compatible with `type`, can only be
 * determined by the data engine, which has the actual data.
 */
final class Operation
{
    public function __construct(
        public readonly CleaningOperationType $type,
        public readonly ?string $column,
        public readonly string|int|float|bool|null $value = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value];

        if ($this->column !== null) {
            $data['column'] = $this->column;
        }

        if ($this->value !== null) {
            $data['value'] = $this->value;
        }

        return $data;
    }
}
