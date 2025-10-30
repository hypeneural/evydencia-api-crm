<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Application\DTO\SchoolDetail;
use App\Application\DTO\SchoolObservation;
use App\Application\DTO\SchoolPanfletagemLog;
use App\Application\DTO\SchoolSummary;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Repositories\SchoolRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SchoolService
{
    private const ALLOWED_MUTATION_TYPES = [
        'updateEscola',
        'togglePanfletagem',
        'createObservacao',
        'updateObservacao',
        'deleteObservacao',
        'createEvento',
        'updateEvento',
        'deleteEvento',
    ];

    public function __construct(
        private readonly SchoolRepositoryInterface $repository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>,
     *     total: int,
     *     max_atualizado_em: string|null
     * }
     */
    public function list(QueryOptions $options): array
    {
        $result = $this->repository->search(
            $options->crmQuery['filters'] ?? [],
            $options->crmQuery['search'] ?? null,
            $options->page,
            $options->perPage,
            $options->fetchAll,
            $options->sort
        );

        $items = array_map(
            static fn (array $row): array => SchoolSummary::fromRepositoryRow($row)->toArray(),
            $result['items'] ?? []
        );

        $count = count($items);
        $page = $options->fetchAll ? 1 : $options->page;
        $perPage = $options->fetchAll ? ($count > 0 ? $count : $options->perPage) : $options->perPage;
        $total = (int) ($result['total'] ?? 0);
        $totalPages = $options->fetchAll ? 1 : ($perPage > 0 ? (int) ceil($total / $perPage) : 1);

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'count' => $count,
            'total' => $total,
            'total_pages' => $totalPages,
            'max_atualizado_em' => $result['max_atualizado_em'] ?? null,
        ];

        return [
            'data' => $items,
            'meta' => $meta,
            'total' => $total,
            'max_atualizado_em' => $result['max_atualizado_em'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $resource = $this->repository->findById($id);
        if ($resource === null) {
            throw new NotFoundException('Escola nao encontrada.');
        }

        return SchoolDetail::fromRepositoryRow($resource)->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, ?int $usuarioId): array
    {
        return $this->repository->transaction(function (SchoolRepositoryInterface $repository) use ($id, $payload, $usuarioId): array {
            $current = $repository->findById($id);
            if ($current === null) {
                throw new NotFoundException('Escola nao encontrada.');
            }

            $expectedVersion = isset($payload['versao_row']) ? (int) $payload['versao_row'] : null;
            $currentVersion = (int) $current['versao_row'];
            if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
                throw new ConflictException('Registro desatualizado.');
            }

            $updateData = [];
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $panfletagemChanged = false;
            $panfletagemObservacao = isset($payload['panfletagem_observacao']) ? trim((string) $payload['panfletagem_observacao']) : null;

            if (array_key_exists('panfletagem', $payload)) {
                $novoStatus = (bool) $payload['panfletagem'];
                $atual = (bool) $current['panfletagem'];
                if ($novoStatus !== $atual) {
                    $panfletagemChanged = true;
                    $updateData['panfletagem'] = $novoStatus ? 1 : 0;
                    $updateData['panfletagem_atualizado_em'] = $now;
                    $updateData['panfletagem_usuario_id'] = $usuarioId;
                }
            }

            if (array_key_exists('obs', $payload)) {
                $updateData['obs'] = $payload['obs'] === null ? null : trim((string) $payload['obs']);
            }

            if (array_key_exists('total_alunos', $payload)) {
                $updateData['total_alunos'] = max(0, (int) $payload['total_alunos']);
            }

            if (array_key_exists('indicadores', $payload)) {
                $indicadores = $payload['indicadores'];
                if ($indicadores !== null && !is_array($indicadores)) {
                    throw new ValidationException([['field' => 'indicadores', 'message' => 'Deve ser um objeto.']]);
                }
                $updateData['indicadores'] = $indicadores === null ? null : json_encode($indicadores, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $periodos = [];
            if (array_key_exists('periodos', $payload)) {
                if (!is_array($payload['periodos'])) {
                    throw new ValidationException([['field' => 'periodos', 'message' => 'Deve ser uma lista.']]);// phpcs:ignore
                }
                $periodos = array_values(array_filter(array_map(
                    static fn ($value): string => trim((string) $value),
                    $payload['periodos']
                ), static fn (string $value): bool => $value !== ''));
            }

            $etapas = [];
            if (array_key_exists('etapas', $payload)) {
                if (!is_array($payload['etapas'])) {
                    throw new ValidationException([['field' => 'etapas', 'message' => 'Deve ser um objeto.']]);// phpcs:ignore
                }

                foreach ($payload['etapas'] as $key => $value) {
                    $etapas[(string) $key] = max(0, (int) $value);
                }
            }

            $updateData['versao_row'] = $currentVersion + 1;
            if ($updateData !== ['versao_row' => $currentVersion + 1]) {
                $repository->update($id, $updateData);
            } else {
                // Apenas incrementa versao para refletir atualizacao de colecoes
                $repository->update($id, ['versao_row' => $currentVersion + 1]);
            }

            if ($periodos !== []) {
                $repository->replacePeriods($id, $periodos);
            } elseif (array_key_exists('periodos', $payload)) {
                $repository->replacePeriods($id, []);
            }

            if ($etapas !== []) {
                $repository->replaceEtapas($id, $etapas);
            } elseif (array_key_exists('etapas', $payload)) {
                $repository->replaceEtapas($id, []);
            }

            if ($panfletagemChanged) {
                $repository->appendPanfletagemLog(
                    $id,
                    $usuarioId,
                    (bool) $current['panfletagem'],
                    (bool) $payload['panfletagem'],
                    $panfletagemObservacao
                );
            }

            $updated = $repository->findById($id);
            if ($updated === null) {
                throw new RuntimeException('Nao foi possivel carregar a escola atualizada.');
            }

            return SchoolDetail::fromRepositoryRow($updated)->toArray();
        });
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function listObservations(int $schoolId, int $page, int $perPage): array
    {
        $result = $this->repository->listObservations($schoolId, $page, $perPage);

        return [
            'items' => array_map(
                static fn (array $row): array => SchoolObservation::fromRepositoryRow($row)->toArray(),
                $result['items'] ?? []
            ),
            'total' => (int) ($result['total'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createObservation(int $schoolId, ?int $usuarioId, string $observacao): array
    {
        $created = $this->repository->createObservation($schoolId, $usuarioId, $observacao);

        return SchoolObservation::fromRepositoryRow($created)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function updateObservation(int $observationId, string $observacao, ?int $usuarioId): array
    {
        $updated = $this->repository->updateObservation($observationId, $observacao, $usuarioId);

        return SchoolObservation::fromRepositoryRow($updated)->toArray();
    }

    public function deleteObservation(int $observationId, ?int $usuarioId): bool
    {
        return $this->repository->deleteObservation($observationId, $usuarioId);
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function listPanfletagemLogs(int $schoolId, int $page, int $perPage): array
    {
        $result = $this->repository->listPanfletagemLogs($schoolId, $page, $perPage);

        return [
            'items' => array_map(
                static fn (array $row): array => SchoolPanfletagemLog::fromRepositoryRow($row)->toArray(),
            $result['items'] ?? []
        ),
            'total' => (int) ($result['total'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCityAggregates(array $filters, bool $includeNeighborhoods): array
    {
        $cities = $this->repository->listCityAggregates($filters, $includeNeighborhoods);

        if ($includeNeighborhoods) {
            foreach ($cities as &$city) {
                if (!isset($city['bairros']) || !is_array($city['bairros'])) {
                    continue;
                }

                $city['bairros'] = $this->transformNeighborhoodAggregates($city['bairros']);
            }
            unset($city);
        }

        return $cities;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNeighborhoodAggregates(int $cityId, array $filters, bool $includeSchools): array
    {
        $neighborhoods = $this->repository->listNeighborhoodAggregates($cityId, $filters, $includeSchools);

        if ($includeSchools) {
            $neighborhoods = $this->transformNeighborhoodAggregates($neighborhoods);
        }

        return $neighborhoods;
    }

    /**
     * @param array<int, array<string, mixed>> $aggregates
     * @param array<string, mixed> $filters
     */
    public function makeAggregatesEtag(array $aggregates, array $filters, string $scopeKey, bool $includeNested): ?string
    {
        if ($aggregates === []) {
            return null;
        }

        [$maxVersion, $maxUpdated] = $this->collectAggregateMetrics($aggregates, $includeNested);

        $payload = json_encode([
            'scope' => $scopeKey,
            'filters' => $filters,
            'version' => $maxVersion,
            'updated' => $maxUpdated,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        return sha1($payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterCities(array $filters): array
    {
        return $this->repository->getFilterCities($filters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterNeighborhoods(array $filters, bool $includeTotals): array
    {
        return $this->repository->getFilterNeighborhoods($filters, $includeTotals);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterPeriods(array $filters): array
    {
        return $this->repository->getFilterPeriods($filters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterTypes(array $filters): array
    {
        return $this->repository->getFilterTypes($filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function getKpiOverview(array $filters): array
    {
        return $this->repository->getKpiOverview($filters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKpiHistorico(array $filters): array
    {
        return $this->repository->getKpiHistorico($filters);
    }

    /**
     * @param array<int, array{client_id: string, tipo: string, payload: array<string, mixed>, versao_row: int|null}> $mutations
     * @return array<int, array<string, mixed>>
     */
    public function enqueueMutations(array $mutations): array
    {
        if (count($mutations) > 20) {
            throw new ValidationException([['field' => 'mutations', 'message' => 'Limite de 20 itens por lote.']]);
        }

        foreach ($mutations as $index => $mutation) {
            if (!isset($mutation['client_id'], $mutation['tipo'], $mutation['payload'])) {
                throw new ValidationException([['field' => sprintf('mutations[%d]', $index), 'message' => 'Campos obrigatorios ausentes.']]);// phpcs:ignore
            }

            $tipo = (string) $mutation['tipo'];
            if (!in_array($tipo, self::ALLOWED_MUTATION_TYPES, true)) {
                throw new ValidationException([['field' => sprintf('mutations[%d].tipo', $index), 'message' => 'Tipo de mutacao nao suportado.']]);
            }
        }

        return $this->repository->enqueueMutations($mutations);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSyncChanges(string $since, int $limit): array
    {
        return $this->repository->getSyncChanges($since, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $neighborhoods
     * @return array<int, array<string, mixed>>
     */
    private function transformNeighborhoodAggregates(array $neighborhoods): array
    {
        foreach ($neighborhoods as &$neighborhood) {
            if (!isset($neighborhood['escolas']) || !is_array($neighborhood['escolas'])) {
                continue;
            }

            $neighborhood['escolas'] = array_map(
                static fn (array $row): array => SchoolSummary::fromRepositoryRow($row)->toArray(),
                $neighborhood['escolas']
            );
        }
        unset($neighborhood);

        return $neighborhoods;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{0:int,1:string|null}
     */
    private function collectAggregateMetrics(array $items, bool $includeNested): array
    {
        $maxVersion = 0;
        $latestTimestamp = null;

        $stack = $items;
        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            if (isset($current['versao_row'])) {
                $maxVersion = max($maxVersion, (int) $current['versao_row']);
            }

            if (isset($current['atualizado_em']) && is_string($current['atualizado_em']) && $current['atualizado_em'] !== '') {
                $timestamp = strtotime($current['atualizado_em']);
                if ($timestamp !== false) {
                    if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                        $latestTimestamp = $timestamp;
                    }
                }
            }

            if ($includeNested) {
                foreach (['bairros', 'escolas'] as $childKey) {
                    if (isset($current[$childKey]) && is_array($current[$childKey])) {
                        foreach ($current[$childKey] as $child) {
                            $stack[] = $child;
                        }
                    }
                }
            }
        }

        $latestFormatted = $latestTimestamp !== null
            ? gmdate('Y-m-d\TH:i:s\Z', $latestTimestamp)
            : null;

        return [$maxVersion, $latestFormatted];
    }
}
