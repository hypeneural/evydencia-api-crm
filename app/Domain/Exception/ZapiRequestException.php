<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class ZapiRequestException extends RuntimeException
{
    /**
     * @param array<string, mixed>|array<int, mixed>|string|null $body
     */
    public function __construct(
        private readonly int $status,
        private readonly array|string|null $body,
        string $message = 'Z-API request failed.'
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|string|null
     */
    public function getBody(): array|string|null
    {
        return $this->body;
    }
}
