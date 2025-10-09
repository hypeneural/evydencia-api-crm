<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface PasswordRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @param array<int, array{field: string, direction: string}> $sort
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int,
     *     max_updated_at?: string|null
     * }
     */
    public function search(
        array $filters,
        ?string $search,
        int $page,
        int $perPage,
        bool $fetchAll,
        array $sort
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByLocalAndUsuario(string $local, string $usuario): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array;

    public function softDelete(string $id, ?string $userId = null): bool;

    /**
     * @param array<int, string> $ids
     * @param array<string, mixed> $data
     * @return array{affected: int}
     */
    public function bulkUpdate(array $ids, array $data): array;

    /**
     * @param array<int, string> $ids
     * @return array{affected: int}
     */
    public function bulkDelete(array $ids): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function stats(array $filters): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array{local: string, count: int}>
     */
    public function platforms(array $filters, int $limit, ?int $minCount): array;

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array{field: string, direction: string}> $sort
     * @return iterable<array<string, mixed>>
     */
    public function export(array $filters, ?string $search, array $sort): iterable;

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function logAction(
        string $passwordId,
        string $action,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent,
        array $before = [],
        array $after = []
    ): void;
}
