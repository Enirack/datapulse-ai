<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetVersion;
use App\Entity\User;
use App\Enum\DatasetStatus;
use App\Enum\DatasetVersionKind;
use App\Repository\DatasetVersionRepository;
use App\Storage\DatasetStorageInterface;
use App\Storage\StorageException;
use App\Upload\FilenameSanitizer;
use App\Upload\FileTypeGuard;
use App\Upload\IngestionResult;
use App\Upload\UploadRejectedException;
use App\Upload\Validator\CsvValidator;
use App\Upload\Validator\JsonValidator;
use App\Upload\Validator\XlsxValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Orchestrates a single dataset upload end to end: validate, checksum,
 * store, persist. Nothing is persisted or written to permanent storage
 * unless every validation step passes first.
 */
final class DatasetIngestionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatasetStorageInterface $storage,
        private readonly DatasetVersionRepository $versionRepository,
        private readonly FilenameSanitizer $filenameSanitizer,
        private readonly FileTypeGuard $typeGuard,
        private readonly CsvValidator $csvValidator,
        private readonly XlsxValidator $xlsxValidator,
        private readonly JsonValidator $jsonValidator,
        private readonly int $maxUploadSize,
    ) {
    }

    public function ingest(User $owner, ?UploadedFile $file, ?string $name, ?string $description): IngestionResult
    {
        if ($file === null) {
            throw UploadRejectedException::missingFile();
        }

        if (!$file->isValid()) {
            $error = $file->getError();
            if (\in_array($error, [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                throw UploadRejectedException::tooLarge($this->maxUploadSize);
            }

            throw UploadRejectedException::invalidFilename();
        }

        $size = $file->getSize();
        if ($size === false) {
            throw UploadRejectedException::storageFailure();
        }

        // A 0-byte upload is not a malformed filename — let the
        // format-specific validator reject it with a meaningful message
        // (e.g. "CSV file has no parseable rows").
        if ($size > $this->maxUploadSize) {
            throw UploadRejectedException::tooLarge($this->maxUploadSize);
        }

        $sanitizedFilename = $this->filenameSanitizer->sanitize($file->getClientOriginalName());
        $extension = $this->filenameSanitizer->extractExtension($sanitizedFilename);

        $sourcePath = $file->getPathname();

        $detectedMimeType = $this->typeGuard->detectMimeType($sourcePath);
        $this->typeGuard->assertPlausible($extension, $detectedMimeType);

        $validator = match ($extension) {
            'csv' => $this->csvValidator,
            'xlsx' => $this->xlsxValidator,
            'json' => $this->jsonValidator,
        };

        $validationResult = $validator->validate($sourcePath);
        if (!$validationResult->valid) {
            throw UploadRejectedException::invalidContent($validationResult->errors);
        }

        $checksum = hash_file('sha256', $sourcePath);
        if ($checksum === false) {
            throw UploadRejectedException::storageFailure();
        }

        $duplicateVersion = $this->versionRepository->findOneByChecksumForOwner($checksum, $owner);
        $duplicateOf = $duplicateVersion?->getDataset();

        $datasetName = $name !== null && trim($name) !== '' ? trim($name) : $sanitizedFilename;
        $trimmedDescription = $description !== null && trim($description) !== '' ? trim($description) : null;

        $dataset = new Dataset($owner, $datasetName, $trimmedDescription);
        $this->em->persist($dataset);

        try {
            $storageIdentifier = $this->storage->store($sourcePath, $extension);
        } catch (StorageException) {
            throw UploadRejectedException::storageFailure();
        }

        $version = new DatasetVersion(
            $dataset,
            1,
            DatasetVersionKind::Raw,
            $sanitizedFilename,
            $storageIdentifier,
            $detectedMimeType,
            $size,
            $checksum,
            $validationResult->formulaInjectionRisk,
        );
        $this->em->persist($version);

        $dataset->setStatus(DatasetStatus::Ready);

        $this->em->flush();

        return new IngestionResult($dataset, $version, $duplicateOf);
    }
}
