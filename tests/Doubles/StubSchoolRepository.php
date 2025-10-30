<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Repositories\SchoolRepositoryInterface;
use BadMethodCallException;

final class StubSchoolRepository implements SchoolRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $cityAggregates = [];

    /** @var array<int, array<string, mixed>> */
    public array $neighborhoodAggregates = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $neighborhoodAggregatesByCity = [];

    /** @var array<string, mixed> */
    public array $lastCityAggregateFilters = [];

    /** @var array<string, mixed> */
    public array $lastNeighborhoodAggregateFilters = [];

    public bool $lastIncludeNeighborhoodSchools = false;

    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array
    {
        return [
            'items' => [],
            'total' => 0,
            'max_atualizado_em' => null,
        ];
    }

    public function findById(int $id): ?array
    {
        return null;
    }

    public function replacePeriods(int $schoolId, array $periods): void
    {
        throw new BadMethodCallException('replacePeriods not implemented in stub');
    }

    public function replaceEtapas(int $schoolId, array $etapas): void
    {
        throw new BadMethodCallException('replaceEtapas not implemented in stub');
    }

    public function update(int $schoolId, array $payload): void
    {
        throw new BadMethodCallException('update not implemented in stub');
    }

    public function appendPanfletagemLog(int $schoolId, ?int $usuarioId, bool $statusAnterior, bool $statusNovo, ?string $observacao): void
    {
        throw new BadMethodCallException('appendPanfletagemLog not implemented in stub');
    }

    public function listPanfletagemLogs(int $schoolId, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function listObservations(int $schoolId, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function createObservation(int $schoolId, ?int $usuarioId, string $observacao): array
    {
        throw new BadMethodCallException('createObservation not implemented in stub');
    }

    public function updateObservation(int $observationId, string $observacao, ?int $usuarioId): array
    {
        throw new BadMethodCallException('updateObservation not implemented in stub');
    }

    public function deleteObservation(int $observationId, ?int $usuarioId): bool
    {
        return false;
    }

    public function getFilterCities(array $filters): array
    {
        return [];
    }

    public function getFilterNeighborhoods(array $filters, bool $includeTotals): array
    {
        return [];
    }

    public function getFilterPeriods(array $filters): array
    {
        return [];
    }

    public function getFilterTypes(array $filters): array
    {
        return [];
    }

    public function getKpiOverview(array $filters): array
    {
        return [];
    }

    public function getKpiHistorico(array $filters): array
    {
        return [];
    }

    public function enqueueMutations(array $mutations): array
    {
        return [];
    }

    public function getSyncChanges(string $since, int $limit): array
    {
        return [];
    }

    public function getCurrentTimestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function transaction(callable $callback)
    {
        return $callback($this);
    }

    public function listCityAggregates(array $filters, bool $includeNeighborhoods): array
    {
        $this->lastCityAggregateFilters = $filters;

        return $this->cityAggregates;
    }

    public function listNeighborhoodAggregates(int $cityId, array $filters, bool $includeSchools): array
    {
        $this->lastNeighborhoodAggregateFilters = $filters;
        $this->lastIncludeNeighborhoodSchools = $includeSchools;

        if (isset($this->neighborhoodAggregatesByCity[$cityId])) {
            return $this->neighborhoodAggregatesByCity[$cityId];
        }

        return $this->neighborhoodAggregates;
    }
}

