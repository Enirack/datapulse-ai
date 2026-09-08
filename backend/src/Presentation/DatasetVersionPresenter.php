<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\DatasetVersion;

/**
 * Builds the public API representation of a DatasetVersion. Deliberately
 * excludes the storage identifier — clients never see where a file lives
 * on disk.
 */
final class DatasetVersionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DatasetVersion $version): array
    {
        return [
            'id' => (string) $version->getId(),
            'versionNumber' => $version->getVersionNumber(),
            'kind' => $version->getKind()->value,
            'originalFilename' => $version->getOriginalFilename(),
            'mimeType' => $version->getMimeType(),
            'fileSize' => $version->getFileSize(),
            'checksum' => $version->getChecksum(),
            'hasFormulaInjectionRisk' => $version->hasFormulaInjectionRisk(),
            'createdAt' => $version->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
