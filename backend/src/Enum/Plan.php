<?php

declare(strict_types=1);

namespace App\Enum;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    public function maxDatasets(): int
    {
        return match ($this) {
            self::Free => 2,
            self::Pro, self::Business => 100000,
        };
    }

    public function maxUploadSizeBytes(): int
    {
        return match ($this) {
            self::Free => 5 * 1024 * 1024,        // 5 MiB
            self::Pro => 50 * 1024 * 1024,       // 50 MiB
            self::Business => 200 * 1024 * 1024, // 200 MiB
        };
    }

    public function canUseAiAnalyst(): bool
    {
        return match ($this) {
            self::Free => false,
            self::Pro, self::Business => true,
        };
    }

    public function canApplyCleaning(): bool
    {
        return match ($this) {
            self::Free => false,
            self::Pro, self::Business => true,
        };
    }
}
