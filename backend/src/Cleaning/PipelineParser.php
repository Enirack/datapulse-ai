<?php

declare(strict_types=1);

namespace App\Cleaning;

use App\Enum\CleaningOperationType;

/**
 * Structural validation of a requested pipeline: known operation types,
 * required parameters present and well-formed, reasonable size. Does NOT
 * know whether a column actually exists in the dataset or whether its
 * type is compatible with the operation — only the data engine, which
 * has the real data, can determine that (see docs/data-cleaning.md).
 */
final class PipelineParser
{
    private const MAX_OPERATIONS = 50;
    private const MAX_COLUMN_NAME_LENGTH = 255;

    /**
     * @param mixed $rawOperations the decoded "operations" value from the request body
     *
     * @return list<Operation>
     *
     * @throws PipelineValidationException
     */
    public function parse(mixed $rawOperations): array
    {
        if (!\is_array($rawOperations) || $rawOperations === []) {
            throw new PipelineValidationException('The pipeline must contain at least one operation.');
        }

        if (\count($rawOperations) > self::MAX_OPERATIONS) {
            throw new PipelineValidationException(\sprintf('The pipeline cannot contain more than %d operations.', self::MAX_OPERATIONS));
        }

        $operations = [];
        foreach ($rawOperations as $index => $raw) {
            $operations[] = $this->parseOne($raw, (int) $index);
        }

        return $operations;
    }

    /**
     * Canonical, order-preserving JSON representation of a parsed
     * pipeline — used as the basis for the idempotency fingerprint.
     *
     * @param list<Operation> $operations
     */
    public function fingerprint(array $operations, string $sourceChecksum): string
    {
        $canonical = array_map(static fn (Operation $op) => $op->toArray(), $operations);
        $json = json_encode($canonical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return hash('sha256', $sourceChecksum.'|'.$json);
    }

    private function parseOne(mixed $raw, int $index): Operation
    {
        if (!\is_array($raw)) {
            throw new PipelineValidationException(\sprintf('Operation #%d must be an object.', $index + 1));
        }

        $rawType = $raw['type'] ?? null;
        if (!\is_string($rawType)) {
            throw new PipelineValidationException(\sprintf('Operation #%d is missing a "type".', $index + 1));
        }

        $type = CleaningOperationType::tryFrom($rawType);
        if ($type === null) {
            throw new PipelineValidationException(\sprintf('Operation #%d has an unknown type "%s".', $index + 1, $rawType));
        }

        $column = null;
        if (\array_key_exists('column', $raw)) {
            if (!\is_string($raw['column']) || trim($raw['column']) === '') {
                throw new PipelineValidationException(\sprintf('Operation #%d has an invalid "column".', $index + 1));
            }
            if (\strlen($raw['column']) > self::MAX_COLUMN_NAME_LENGTH) {
                throw new PipelineValidationException(\sprintf('Operation #%d has a "column" name that is too long.', $index + 1));
            }
            $column = $raw['column'];
        }

        if ($type->requiresColumn() && $column === null) {
            throw new PipelineValidationException(\sprintf('Operation #%d ("%s") requires a "column".', $index + 1, $type->value));
        }

        $value = null;
        if (\array_key_exists('value', $raw)) {
            if (!\is_scalar($raw['value']) && $raw['value'] !== null) {
                throw new PipelineValidationException(\sprintf('Operation #%d has an invalid "value".', $index + 1));
            }
            $value = $raw['value'];
        }

        if ($type->requiresValue() && $value === null) {
            throw new PipelineValidationException(\sprintf('Operation #%d ("%s") requires a "value".', $index + 1, $type->value));
        }

        return new Operation($type, $column, $value);
    }
}
