<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\Dataset;
use App\Entity\DatasetVersion;

final class IngestionResult
{
    public function __construct(
        public readonly Dataset $dataset,
        public readonly DatasetVersion $version,
        public readonly ?Dataset $duplicateOf,
    ) {
    }
}
