<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface SchoolRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @param array<int, array{field: string, direction: string}> $sort
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int,
     *     max_atualizado_em: string|null
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
    public function findById(int $id): ?array;

    /**
     * @param array<int, string> $periods
     */
    public function replacePeriods(int $schoolId, array $periods): void;

    /**
     * @param array<string, int> $etapas
     */
    public function replaceEtapas(int $schoolId, array $etapas): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $schoolId, array $payload): void;

    public function appendPanfletagemLog(
        int $schoolId,
        ?int $usuarioId,
        bool $statusAnterior,
        bool $statusNovo,
        ?string $observacao
    ): void;

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function listPanfletagemLogs(int $schoolId, int $page, int $perPage): array;

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function listObservations(int $schoolId, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>
     */
    public function createObservation(int $schoolId, ?int $usuarioId, string $observacao): array;

    /**
     * @return array<string, mixed>
     */
    public function updateObservation(int $observationId, string $observacao, ?int $usuarioId): array;

    public function deleteObservation(int $observationId, ?int $usuarioId): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterCities(array $filters): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterNeighborhoods(array $filters, bool $includeTotals): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterPeriods(array $filters): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterTypes(array $filters): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCityAggregates(array $filters, bool $includeNeighborhoods): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNeighborhoodAggregates(int $cityId, array $filters, bool $includeSchools): array;

    /**
     * @return array<string, mixed>
     */
    public function getKpiOverview(array $filters): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKpiHistorico(array $filters): array;

    /**
     * @param array<int, array{client_id: string, tipo: string, payload: array<string, mixed>, versao_row: int|null}> $mutations
     * @return array<int, array<string, mixed>>
     */
    public function enqueueMutations(array $mutations): array;

    /**
     * @return array<string, mixed>
     */
    public function getSyncChanges(string $since, int $limit): array;

    public function getCurrentTimestamp(): string;

    /**
     * @template T
     * @param callable(self):T $callback
     * @return T
     */
    public function transaction(callable $callback);
}
