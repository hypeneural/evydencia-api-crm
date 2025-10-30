<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface EventRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function list(array $filters, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array;

    public function delete(int $id, ?int $usuarioId): bool;

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function listLogs(int $eventId, int $page, int $perPage): array;
}
