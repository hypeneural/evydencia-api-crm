<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface OrderRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array;

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(string $uuid, array $payload): void;
}
