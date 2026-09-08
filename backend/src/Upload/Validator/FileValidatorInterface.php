<?php

declare(strict_types=1);

namespace App\Upload\Validator;

use App\Upload\ValidationResult;

interface FileValidatorInterface
{
    /**
     * $path is a local filesystem path to the file's content — validators
     * must stream/bound their reads and never assume the whole file fits
     * comfortably in memory.
     */
    public function validate(string $path): ValidationResult;
}
