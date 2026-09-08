<?php

declare(strict_types=1);

namespace App\Storage;

interface DatasetStorageInterface
{
    /**
     * Moves the file at $sourcePath into permanent storage and returns an
     * opaque storage identifier. Implementations must generate this
     * identifier themselves — the caller-supplied extension is the only
     * external input allowed to influence it, and must already be validated
     * against a fixed whitelist by the caller.
     */
    public function store(string $sourcePath, string $extension): string;

    /**
     * Resolves a storage identifier to an absolute, readable filesystem path.
     *
     * @throws StorageException if the identifier doesn't resolve to a file
     *                           inside the storage root, or that file is missing.
     */
    public function retrieve(string $storageIdentifier): string;

    public function delete(string $storageIdentifier): void;

    public function exists(string $storageIdentifier): bool;
}
