<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface ScheduledPostRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @param array<int, array{field: string, direction: string}> $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, max_updated_at: string|null}
     */
    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array;

    public function findById(int $id): ?array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $payload): ?array;

    public function delete(int $id): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findReady(int $limit): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function markSent(int $id, array $payload): ?array;
}
