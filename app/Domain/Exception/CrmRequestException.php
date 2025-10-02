<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class CrmRequestException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly array $payload,
        string $message = 'Evydencia CRM returned an error.'
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
