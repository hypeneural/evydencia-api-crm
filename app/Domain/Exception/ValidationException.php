<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<int, array<string, string>> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed.', 422);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
