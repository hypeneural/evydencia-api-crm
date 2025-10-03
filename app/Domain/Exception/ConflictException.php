<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class ConflictException extends RuntimeException
{
    /**
     * @param array<int, array<string, string>> $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message, 409);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
