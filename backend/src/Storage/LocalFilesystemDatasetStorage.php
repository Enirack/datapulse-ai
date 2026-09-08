<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\Uid\Uuid;

/**
 * Stores dataset files on the local filesystem, outside the web root.
 *
 * Every stored file lives at a path this class generates itself
 * (`{2-char shard}/{uuid}.{extension}`) — user-supplied filenames never
 * reach the filesystem layer, which is what makes path traversal and
 * symlink-swap attacks structurally impossible here rather than merely
 * filtered. `$sourcePath` must be a trusted, already-validated temporary
 * file path (e.g. Symfony's `UploadedFile::getPathname()`), since this
 * class does not itself verify that the source came from a real upload.
 */
final class LocalFilesystemDatasetStorage implements DatasetStorageInterface
{
    private readonly string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/\\');
    }

    public function store(string $sourcePath, string $extension): string
    {
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            throw new StorageException('Source file is not a regular file.');
        }

        $this->ensureDirectory($this->rootDir);

        $uuid = Uuid::v4()->toRfc4122();
        $shard = substr($uuid, 0, 2);
        $shardDir = $this->rootDir.'/'.$shard;
        $this->ensureDirectory($shardDir);

        $identifier = $shard.'/'.$uuid.'.'.$extension;
        $destination = $this->rootDir.'/'.$identifier;

        if (!@rename($sourcePath, $destination)) {
            // rename() fails across filesystem boundaries (e.g. /tmp on a
            // different mount); fall back to copy + delete.
            if (!@copy($sourcePath, $destination)) {
                throw new StorageException('Failed to store uploaded file.');
            }
            @unlink($sourcePath);
        }

        @chmod($destination, 0640);

        return $identifier;
    }

    public function retrieve(string $storageIdentifier): string
    {
        $path = $this->resolve($storageIdentifier);

        if (!is_file($path) || is_link($path)) {
            throw new StorageException('Stored file not found.');
        }

        return $path;
    }

    public function delete(string $storageIdentifier): void
    {
        $path = $this->resolve($storageIdentifier, requireExists: false);

        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    public function exists(string $storageIdentifier): bool
    {
        try {
            $path = $this->resolve($storageIdentifier);
        } catch (StorageException) {
            return false;
        }

        return is_file($path) && !is_link($path);
    }

    private function resolve(string $storageIdentifier, bool $requireExists = true): string
    {
        if ($storageIdentifier === '' || str_contains($storageIdentifier, "\0") || str_contains($storageIdentifier, '..')) {
            throw new StorageException('Invalid storage identifier.');
        }

        $candidate = $this->rootDir.'/'.$storageIdentifier;
        $real = realpath($candidate);
        $realRoot = realpath($this->rootDir);

        if ($real === false) {
            if ($requireExists) {
                throw new StorageException('Stored file not found.');
            }

            // Path doesn't exist (already deleted); still confirm it *would*
            // resolve inside the storage root before returning it.
            $normalizedCandidate = str_replace('\\', '/', $candidate);
            $normalizedRoot = str_replace('\\', '/', (string) $realRoot);
            if ($realRoot === false || !str_starts_with($normalizedCandidate, $normalizedRoot.'/')) {
                throw new StorageException('Invalid storage identifier.');
            }

            return $candidate;
        }

        if ($realRoot === false || !str_starts_with($real, $realRoot)) {
            throw new StorageException('Invalid storage identifier.');
        }

        return $real;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_link($dir)) {
            throw new StorageException('Refusing to use a symlinked storage directory.');
        }

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new StorageException(\sprintf('Failed to create storage directory "%s".', $dir));
        }
    }
}
