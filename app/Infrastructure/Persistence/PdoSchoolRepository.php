<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\SchoolRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoSchoolRepository implements SchoolRepositoryInterface
{
    private const PERIODOS = ['Matutino', 'Vespertino', 'Noturno'];

    private const ETAPAS = [
        'bercario_0a1',
        'bercario_1a2',
        'maternal_2a3',
        'jardim_3a4',
        'preI_4a5',
        'preII_5a6',
        'ano1_6a7',
        'ano2_7a8',
        'ano3_8a9',
        'ano4_9a10',
    ];

    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array
    {
        $pdo = $this->requireConnection();

        $clauses = $this->buildFilterClause($filters, $search);
        $where = $clauses['where'];
        $params = $clauses['params'];

        $orderBy = $this->buildOrderBy($sort);
        $limitFragment = $fetchAll ? '' : ' LIMIT :limit OFFSET :offset';

        $baseFrom = '
            FROM escolas e
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
            LEFT JOIN usuarios u ON u.id = e.panfletagem_usuario_id
        ';

        $sql = '
            SELECT
                e.id,
                e.cidade_id,
                c.nome AS cidade_nome,
                c.sigla_uf AS cidade_sigla_uf,
                e.bairro_id,
                b.nome AS bairro_nome,
                e.tipo,
                e.nome,
                e.diretor,
                e.endereco,
                e.total_alunos,
                e.panfletagem,
                e.panfletagem_atualizado_em,
                e.panfletagem_usuario_id,
                u.nome AS panfletagem_usuario_nome,
                e.indicadores,
                e.obs,
                e.versao_row,
                e.criado_em,
                e.atualizado_em
            ' . $baseFrom . $where . $orderBy . $limitFragment;

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);

        if (!$fetchAll) {
            $offset = max(0, ($page - 1) * $perPage);
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countSql = '
            SELECT COUNT(*) AS total, MAX(e.atualizado_em) AS max_atualizado_em
        ' . $baseFrom . $where;
        $countStatement = $pdo->prepare($countSql);
        $this->bindParameters($countStatement, $params);
        $countStatement->execute();
        $countRow = $countStatement->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'max_atualizado_em' => null];

        $schoolIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $periodsBySchool = $this->fetchPeriods($schoolIds);

        $items = array_map(function (array $row) use ($periodsBySchool): array {
            $id = (int) $row['id'];

            return [
                'id' => $id,
                'cidade_id' => (int) $row['cidade_id'],
                'cidade_nome' => $row['cidade_nome'],
                'cidade_sigla_uf' => $row['cidade_sigla_uf'],
                'bairro_id' => (int) $row['bairro_id'],
                'bairro_nome' => $row['bairro_nome'],
                'tipo' => $row['tipo'],
                'nome' => $row['nome'],
                'diretor' => $row['diretor'],
                'endereco' => $row['endereco'],
                'total_alunos' => (int) $row['total_alunos'],
                'panfletagem' => (bool) ((int) $row['panfletagem']),
                'panfletagem_atualizado_em' => $this->formatDateTime($row['panfletagem_atualizado_em']),
                'panfletagem_usuario' => $row['panfletagem_usuario_id'] !== null ? [
                    'id' => (int) $row['panfletagem_usuario_id'],
                    'nome' => $row['panfletagem_usuario_nome'],
                ] : null,
                'indicadores' => $this->decodeJson($row['indicadores']),
                'obs' => $row['obs'],
                'versao_row' => (int) $row['versao_row'],
                'criado_em' => $this->formatDateTime($row['criado_em']),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
                'periodos' => $periodsBySchool[$id] ?? [],
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => (int) ($countRow['total'] ?? 0),
            'max_atualizado_em' => $this->formatDateTime($countRow['max_atualizado_em'] ?? null),
        ];
    }

    public function findById(int $id): ?array
    {
        $pdo = $this->requireConnection();

        $sql = '
            SELECT
                e.id,
                e.cidade_id,
                c.nome AS cidade_nome,
                c.sigla_uf AS cidade_sigla_uf,
                e.bairro_id,
                b.nome AS bairro_nome,
                e.tipo,
                e.nome,
                e.diretor,
                e.endereco,
                e.total_alunos,
                e.panfletagem,
                e.panfletagem_atualizado_em,
                e.panfletagem_usuario_id,
                u.nome AS panfletagem_usuario_nome,
                e.indicadores,
                e.obs,
                e.versao_row,
                e.criado_em,
                e.atualizado_em
            FROM escolas e
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
            LEFT JOIN usuarios u ON u.id = e.panfletagem_usuario_id
            WHERE e.id = :id
            LIMIT 1
        ';

        $statement = $pdo->prepare($sql);
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'cidade_id' => (int) $row['cidade_id'],
            'cidade_nome' => $row['cidade_nome'],
            'cidade_sigla_uf' => $row['cidade_sigla_uf'],
            'bairro_id' => (int) $row['bairro_id'],
            'bairro_nome' => $row['bairro_nome'],
            'tipo' => $row['tipo'],
            'nome' => $row['nome'],
            'diretor' => $row['diretor'],
            'endereco' => $row['endereco'],
            'total_alunos' => (int) $row['total_alunos'],
            'panfletagem' => (bool) ((int) $row['panfletagem']),
            'panfletagem_atualizado_em' => $this->formatDateTime($row['panfletagem_atualizado_em']),
            'panfletagem_usuario' => $row['panfletagem_usuario_id'] !== null ? [
                'id' => (int) $row['panfletagem_usuario_id'],
                'nome' => $row['panfletagem_usuario_nome'],
            ] : null,
            'indicadores' => $this->decodeJson($row['indicadores']),
            'obs' => $row['obs'],
            'versao_row' => (int) $row['versao_row'],
            'criado_em' => $this->formatDateTime($row['criado_em']),
            'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
            'periodos' => $this->getPeriodsForSchool($id),
            'etapas' => $this->getEtapasForSchool($id),
        ];
    }

    public function replacePeriods(int $schoolId, array $periods): void
    {
        $pdo = $this->requireConnection();

        $pdo->prepare('DELETE FROM escola_periodos WHERE escola_id = :id')->execute([':id' => $schoolId]);

        if ($periods === []) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO escola_periodos (escola_id, periodo) VALUES (:escola_id, :periodo)');
        foreach ($periods as $period) {
            $insert->execute([
                ':escola_id' => $schoolId,
                ':periodo' => $period,
            ]);
        }
    }

    public function replaceEtapas(int $schoolId, array $etapas): void
    {
        $pdo = $this->requireConnection();
        $pdo->prepare('DELETE FROM escola_etapas WHERE escola_id = :id')->execute([':id' => $schoolId]);

        if ($etapas === []) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO escola_etapas (escola_id, etapa, quantidade) VALUES (:escola_id, :etapa, :quantidade)');
        foreach ($etapas as $etapa => $quantidade) {
            $insert->execute([
                ':escola_id' => $schoolId,
                ':etapa' => $etapa,
                ':quantidade' => $quantidade,
            ]);
        }
    }

    public function update(int $schoolId, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $pdo = $this->requireConnection();
        $fields = [];
        $params = [':id' => $schoolId];

        foreach ($payload as $column => $value) {
            $placeholder = ':' . $column;
            $fields[] = sprintf('%s = %s', $column, $placeholder);
            $params[$placeholder] = $value;
        }

        $sql = sprintf('UPDATE escolas SET %s WHERE id = :id', implode(', ', $fields));
        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
    }

    public function appendPanfletagemLog(int $schoolId, ?int $usuarioId, bool $statusAnterior, bool $statusNovo, ?string $observacao): void
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO escola_panfletagem_logs (escola_id, usuario_id, status_anterior, status_novo, observacao)
             VALUES (:escola_id, :usuario_id, :status_anterior, :status_novo, :observacao)'
        );

        $statement->execute([
            ':escola_id' => $schoolId,
            ':usuario_id' => $usuarioId,
            ':status_anterior' => $statusAnterior ? 1 : 0,
            ':status_novo' => $statusNovo ? 1 : 0,
            ':observacao' => $observacao,
        ]);
    }

    public function listPanfletagemLogs(int $schoolId, int $page, int $perPage): array
    {
        $pdo = $this->requireConnection();
        $offset = max(0, ($page - 1) * $perPage);

        $statement = $pdo->prepare(
            'SELECT
                l.id,
                l.status_anterior,
                l.status_novo,
                l.observacao,
                l.criado_em,
                u.id AS usuario_id,
                u.nome AS usuario_nome
             FROM escola_panfletagem_logs l
             LEFT JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.escola_id = :id
             ORDER BY l.criado_em DESC
             LIMIT :limit OFFSET :offset'
        );

        $statement->bindValue(':id', $schoolId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM escola_panfletagem_logs WHERE escola_id = :id');
        $countStmt->execute([':id' => $schoolId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $items = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'status_anterior' => (bool) ((int) $row['status_anterior']),
                'status_novo' => (bool) ((int) $row['status_novo']),
                'observacao' => $row['observacao'],
                'criado_em' => $this->formatDateTime($row['criado_em']),
                'usuario' => $row['usuario_id'] !== null ? [
                    'id' => (int) $row['usuario_id'],
                    'nome' => $row['usuario_nome'],
                ] : null,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }

    public function listObservations(int $schoolId, int $page, int $perPage): array
    {
        $pdo = $this->requireConnection();
        $offset = max(0, ($page - 1) * $perPage);

        $statement = $pdo->prepare(
            'SELECT
                o.id,
                o.observacao,
                o.usuario_id,
                o.criado_em,
                o.atualizado_em,
                u.nome AS usuario_nome
            FROM escola_observacoes o
            LEFT JOIN usuarios u ON u.id = o.usuario_id
            WHERE o.escola_id = :id AND o.removido_em IS NULL
            ORDER BY o.criado_em DESC
            LIMIT :limit OFFSET :offset'
        );

        $statement->bindValue(':id', $schoolId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM escola_observacoes WHERE escola_id = :id AND removido_em IS NULL');
        $countStmt->execute([':id' => $schoolId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $items = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'observacao' => $row['observacao'],
                'usuario' => $row['usuario_id'] !== null ? [
                    'id' => (int) $row['usuario_id'],
                    'nome' => $row['usuario_nome'],
                ] : null,
                'criado_em' => $this->formatDateTime($row['criado_em']),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }

    public function createObservation(int $schoolId, ?int $usuarioId, string $observacao): array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO escola_observacoes (escola_id, usuario_id, observacao)
             VALUES (:escola_id, :usuario_id, :observacao)'
        );

        $statement->execute([
            ':escola_id' => $schoolId,
            ':usuario_id' => $usuarioId,
            ':observacao' => $observacao,
        ]);

        $id = (int) $pdo->lastInsertId();
        $created = $this->findObservation($id);
        if ($created === null) {
            throw new RuntimeException('Nao foi possivel carregar a observacao criada.');
        }

        $this->logObservationChange($id, 'create', $usuarioId, null, $created);

        return $created;
    }

    public function updateObservation(int $observationId, string $observacao, ?int $usuarioId): array
    {
        $pdo = $this->requireConnection();
        $before = $this->findObservation($observationId);
        if ($before === null) {
            throw new RuntimeException('Observacao nao encontrada.');
        }

        $statement = $pdo->prepare(
            'UPDATE escola_observacoes
             SET observacao = :observacao, usuario_id = :usuario_id
             WHERE id = :id'
        );

        $statement->execute([
            ':observacao' => $observacao,
            ':usuario_id' => $usuarioId,
            ':id' => $observationId,
        ]);

        $after = $this->findObservation($observationId);
        if ($after === null) {
            throw new RuntimeException('Nao foi possivel atualizar a observacao.');
        }

        $this->logObservationChange($observationId, 'update', $usuarioId, $before, $after);

        return $after;
    }

    public function deleteObservation(int $observationId, ?int $usuarioId): bool
    {
        $pdo = $this->requireConnection();
        $before = $this->findObservation($observationId);
        if ($before === null) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE escola_observacoes SET removido_em = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([':id' => $observationId]);

        if ($statement->rowCount() > 0) {
            $this->logObservationChange($observationId, 'delete', $usuarioId, $before, null);
            return true;
        }

        return false;
    }

    public function listCityAggregates(array $filters, bool $includeNeighborhoods): array
    {
        $pdo = $this->requireConnection();

        [$normalizedFilters, $searchTerm] = $this->normalizeFilterInput($filters);
        $clauses = $this->buildFilterClause($normalizedFilters, $searchTerm);
        $where = $clauses['where'];
        $params = $clauses['params'];

        $sql = '
            SELECT
                c.id,
                c.nome,
                c.sigla_uf,
                COUNT(DISTINCT e.id) AS total_escolas,
                COUNT(DISTINCT e.bairro_id) AS total_bairros,
                COALESCE(SUM(e.total_alunos), 0) AS total_alunos,
                COALESCE(SUM(CASE WHEN e.panfletagem = 1 THEN 1 ELSE 0 END), 0) AS panfletagem_feita,
                COALESCE(SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END), 0) AS panfletagem_pendente,
                MAX(e.atualizado_em) AS atualizado_em,
                MAX(e.versao_row) AS versao_row
            FROM escolas e
            INNER JOIN cidades c ON c.id = e.cidade_id
        ' . $where . '
            GROUP BY c.id, c.nome, c.sigla_uf
            ORDER BY c.nome ASC
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $periodTotals = $this->fetchPeriodTotals($pdo, $where, $params, 'cidade');
        $etapaTotals = $this->fetchEtapaTotals($pdo, $where, $params, 'cidade');

        $result = [];
        foreach ($rows as $row) {
            $cityId = (int) $row['id'];

            $item = [
                'id' => $cityId,
                'nome' => (string) $row['nome'],
                'sigla_uf' => (string) $row['sigla_uf'],
                'totais' => [
                    'total_escolas' => (int) ($row['total_escolas'] ?? 0),
                    'total_bairros' => (int) ($row['total_bairros'] ?? 0),
                    'total_alunos' => (int) ($row['total_alunos'] ?? 0),
                    'panfletagem_feita' => (int) ($row['panfletagem_feita'] ?? 0),
                    'panfletagem_pendente' => (int) ($row['panfletagem_pendente'] ?? 0),
                ],
                'periodos' => $periodTotals[$cityId] ?? $this->defaultPeriodTotals(),
                'etapas' => $etapaTotals[$cityId] ?? $this->defaultEtapaTotals(),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em'] ?? null),
                'versao_row' => (int) ($row['versao_row'] ?? 0),
            ];

            if ($includeNeighborhoods) {
                $item['bairros'] = $this->listNeighborhoodAggregates($cityId, $filters, false);
            }

            $result[] = $item;
        }

        return $result;
    }

    public function listNeighborhoodAggregates(int $cityId, array $filters, bool $includeSchools): array
    {
        $pdo = $this->requireConnection();

        [$normalizedFilters, $searchTerm] = $this->normalizeFilterInput($filters, ['cidade_id' => $cityId]);
        $clauses = $this->buildFilterClause($normalizedFilters, $searchTerm);
        $where = $clauses['where'];
        $params = $clauses['params'];

        $sql = '
            SELECT
                b.id,
                b.nome,
                e.cidade_id,
                COUNT(DISTINCT e.id) AS total_escolas,
                COALESCE(SUM(e.total_alunos), 0) AS total_alunos,
                COALESCE(SUM(CASE WHEN e.panfletagem = 1 THEN 1 ELSE 0 END), 0) AS panfletagem_feita,
                COALESCE(SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END), 0) AS panfletagem_pendente,
                MAX(e.atualizado_em) AS atualizado_em,
                MAX(e.versao_row) AS versao_row
            FROM escolas e
            INNER JOIN bairros b ON b.id = e.bairro_id
            INNER JOIN cidades c ON c.id = e.cidade_id
        ' . $where . '
            GROUP BY b.id, b.nome, e.cidade_id
            ORDER BY b.nome ASC
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $periodTotals = $this->fetchPeriodTotals($pdo, $where, $params, 'bairro');
        $etapaTotals = $this->fetchEtapaTotals($pdo, $where, $params, 'bairro');

        $schoolsByNeighborhood = [];
        if ($includeSchools) {
            $schoolResult = $this->search($normalizedFilters, $searchTerm, 1, 2000, true, []);
            foreach ($schoolResult['items'] ?? [] as $row) {
                $bairroId = (int) $row['bairro_id'];
                $schoolsByNeighborhood[$bairroId][] = $row;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $bairroId = (int) $row['id'];

            $item = [
                'id' => $bairroId,
                'nome' => (string) $row['nome'],
                'cidade_id' => (int) $row['cidade_id'],
                'totais' => [
                    'total_escolas' => (int) ($row['total_escolas'] ?? 0),
                    'total_alunos' => (int) ($row['total_alunos'] ?? 0),
                    'panfletagem_feita' => (int) ($row['panfletagem_feita'] ?? 0),
                    'panfletagem_pendente' => (int) ($row['panfletagem_pendente'] ?? 0),
                ],
                'periodos' => $periodTotals[$bairroId] ?? $this->defaultPeriodTotals(),
                'etapas' => $etapaTotals[$bairroId] ?? $this->defaultEtapaTotals(),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em'] ?? null),
                'versao_row' => (int) ($row['versao_row'] ?? 0),
            ];

            if ($includeSchools) {
                $item['escolas'] = $schoolsByNeighborhood[$bairroId] ?? [];
            }

            $result[] = $item;
        }

        return $result;
    }

    public function getFilterCities(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clauses = $this->buildFilterClause(array_diff_key($filters, ['cidade_id' => true]), $filters['search'] ?? null);
        $params = $clauses['params'];
        $where = $clauses['where'];

        $sql = '
            SELECT
                c.id,
                c.nome,
                c.sigla_uf,
                COUNT(e.id) AS total_escolas,
                SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END) AS total_pendentes
            FROM cidades c
            LEFT JOIN escolas e ON e.cidade_id = c.id
        ';

        if ($where !== '') {
            $sql .= ' ' . str_replace('WHERE', 'AND', $where);
        }

        $sql .= ' GROUP BY c.id, c.nome, c.sigla_uf ORDER BY c.nome';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nome' => $row['nome'],
            'sigla_uf' => $row['sigla_uf'],
            'total_escolas' => (int) ($row['total_escolas'] ?? 0),
            'total_pendentes' => (int) ($row['total_pendentes'] ?? 0),
        ], $rows);
    }

    public function getFilterNeighborhoods(array $filters, bool $includeTotals): array
    {
        $pdo = $this->requireConnection();

        $params = [];
        $cidadeIds = $this->normalizeIntList($filters['cidade_id'] ?? null);
        $cidadeClause = $cidadeIds === [] ? '' : 'WHERE b.cidade_id IN (' . $this->createInPlaceholder('cidade', $cidadeIds, $params) . ')';

        $sql = '
            SELECT b.id, b.nome, b.cidade_id
        ';
        if ($includeTotals) {
            $sql .= ',
                COUNT(e.id) AS total_escolas,
                SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END) AS total_pendentes
            ';
        }

        $sql .= '
            FROM bairros b
            LEFT JOIN escolas e ON e.bairro_id = b.id
            ' . $cidadeClause . '
            GROUP BY b.id, b.nome, b.cidade_id
            ORDER BY b.nome
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $row) use ($includeTotals): array {
            $payload = [
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'cidade_id' => (int) $row['cidade_id'],
            ];

            if ($includeTotals) {
                $payload['total_escolas'] = (int) ($row['total_escolas'] ?? 0);
                $payload['total_pendentes'] = (int) ($row['total_pendentes'] ?? 0);
            }

            return $payload;
        }, $rows);
    }

    public function getFilterPeriods(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clauses = $this->buildFilterClause($filters, $filters['search'] ?? null);
        $params = $clauses['params'];
        $where = $clauses['where'];

        $sql = '
            SELECT ep.periodo, COUNT(DISTINCT ep.escola_id) AS total
            FROM escola_periodos ep
            INNER JOIN escolas e ON e.id = ep.escola_id
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where . '
            GROUP BY ep.periodo
            ORDER BY ep.periodo
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'periodo' => $row['periodo'],
            'total_escolas' => (int) ($row['total'] ?? 0),
        ], $rows);
    }

    public function getFilterTypes(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clauses = $this->buildFilterClause($filters, $filters['search'] ?? null);
        $params = $clauses['params'];
        $where = $clauses['where'];

        $sql = '
            SELECT e.tipo, COUNT(*) AS total
            FROM escolas e
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where . '
            GROUP BY e.tipo
            ORDER BY e.tipo
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'tipo' => $row['tipo'],
            'total_escolas' => (int) ($row['total'] ?? 0),
        ], $rows);
    }

    public function getKpiOverview(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clauses = $this->buildFilterClause($filters, $filters['search'] ?? null);
        $params = $clauses['params'];
        $where = $clauses['where'];

        $totaisSql = '
            SELECT
                COUNT(*) AS total_escolas,
                SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN e.panfletagem = 1 THEN 1 ELSE 0 END) AS feitas,
                SUM(e.total_alunos) AS total_alunos
            FROM escolas e
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where;

        $totaisStmt = $pdo->prepare($totaisSql);
        $this->bindParameters($totaisStmt, $params);
        $totaisStmt->execute();
        $totais = $totaisStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_escolas' => 0,
            'pendentes' => 0,
            'feitas' => 0,
            'total_alunos' => 0,
        ];

        $etapasStmt = $pdo->prepare('
            SELECT ee.etapa, SUM(ee.quantidade) AS total
            FROM escola_etapas ee
            INNER JOIN escolas e ON e.id = ee.escola_id
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where . '
            GROUP BY ee.etapa
            ORDER BY ee.etapa
        ');
        $this->bindParameters($etapasStmt, $params);
        $etapasStmt->execute();
        $etapas = $etapasStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $turnosStmt = $pdo->prepare('
            SELECT ep.periodo, COUNT(DISTINCT ep.escola_id) AS total
            FROM escola_periodos ep
            INNER JOIN escolas e ON e.id = ep.escola_id
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where . '
            GROUP BY ep.periodo
            ORDER BY ep.periodo
        ');
        $this->bindParameters($turnosStmt, $params);
        $turnosStmt->execute();
        $turnos = $turnosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bairrosStmt = $pdo->prepare('
            SELECT
                b.id AS bairro_id,
                b.nome AS bairro_nome,
                SUM(CASE WHEN e.panfletagem = 0 THEN 1 ELSE 0 END) AS pendentes,
                COUNT(e.id) AS total
            FROM escolas e
            INNER JOIN bairros b ON b.id = e.bairro_id
            INNER JOIN cidades c ON c.id = e.cidade_id
        ' . $where . '
            GROUP BY b.id, b.nome
            ORDER BY pendentes DESC, total DESC
            LIMIT 5
        ');
        $this->bindParameters($bairrosStmt, $params);
        $bairrosStmt->execute();
        $bairros = $bairrosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'totais' => [
                'escolas' => (int) ($totais['total_escolas'] ?? 0),
                'pendentes' => (int) ($totais['pendentes'] ?? 0),
                'feitas' => (int) ($totais['feitas'] ?? 0),
                'alunos' => (int) ($totais['total_alunos'] ?? 0),
            ],
            'etapas' => array_map(static fn (array $row): array => [
                'etapa' => $row['etapa'],
                'alunos' => (int) ($row['total'] ?? 0),
            ], $etapas),
            'turnos' => array_map(static fn (array $row): array => [
                'periodo' => $row['periodo'],
                'escolas' => (int) ($row['total'] ?? 0),
            ], $turnos),
            'bairros_top' => array_map(static fn (array $row): array => [
                'bairro_id' => (int) $row['bairro_id'],
                'bairro_nome' => $row['bairro_nome'],
                'pendentes' => (int) ($row['pendentes'] ?? 0),
                'total' => (int) ($row['total'] ?? 0),
            ], $bairros),
        ];
    }

    public function getKpiHistorico(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clauses = $this->buildFilterClause($filters, $filters['search'] ?? null);
        $params = $clauses['params'];
        $where = $clauses['where'];

        $sql = '
            SELECT
                DATE(l.criado_em) AS periodo,
                SUM(CASE WHEN l.status_novo = 1 THEN 1 ELSE 0 END) AS feitas,
                SUM(CASE WHEN l.status_novo = 0 THEN 1 ELSE 0 END) AS pendentes
            FROM escola_panfletagem_logs l
            INNER JOIN escolas e ON e.id = l.escola_id
            INNER JOIN cidades c ON c.id = e.cidade_id
            INNER JOIN bairros b ON b.id = e.bairro_id
        ' . $where . '
            GROUP BY DATE(l.criado_em)
            ORDER BY periodo DESC
            LIMIT 180
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'periodo' => (string) $row['periodo'],
            'feitas' => (int) ($row['feitas'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
        ], $rows);
    }

    public function enqueueMutations(array $mutations): array
    {
        $pdo = $this->requireConnection();
        if ($mutations === []) {
            return [];
        }

        $statement = $pdo->prepare(
            'INSERT INTO sync_mutations (client_id, tipo, payload, versao_row)
             VALUES (:client_id, :tipo, :payload, :versao_row)'
        );

        $result = [];
        foreach ($mutations as $mutation) {
            $statement->execute([
                ':client_id' => $mutation['client_id'],
                ':tipo' => $mutation['tipo'],
                ':payload' => json_encode($mutation['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':versao_row' => $mutation['versao_row'],
            ]);

            $result[] = [
                'id' => (int) $pdo->lastInsertId(),
                'client_id' => $mutation['client_id'],
                'tipo' => $mutation['tipo'],
                'status' => 'pending',
            ];
        }

        return $result;
    }

    public function getSyncChanges(string $since, int $limit): array
    {
        $pdo = $this->requireConnection();

        $limit = max(1, min(500, $limit));
        $sinceDateTime = $this->parseTimestamp($since ?: '1970-01-01T00:00:00Z');
        $sinceFormatted = $sinceDateTime->format('Y-m-d H:i:s');

        $cidades = $this->fetchSyncEntities(
            $pdo,
            'SELECT id, nome, sigla_uf, criado_em, atualizado_em
             FROM cidades
             WHERE criado_em > :since_created OR atualizado_em > :since_updated
             ORDER BY atualizado_em
             LIMIT :limit',
            [
                'since_created' => $sinceFormatted,
                'since_updated' => $sinceFormatted,
                'limit' => $limit,
            ],
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'nome' => $row['nome'],
                'sigla_uf' => $row['sigla_uf'],
                'criado_em' => $this->formatDateTime($row['criado_em']),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
            ]
        );

        $bairros = $this->fetchSyncEntities(
            $pdo,
            'SELECT id, cidade_id, nome, criado_em, atualizado_em
             FROM bairros
             WHERE criado_em > :since_created OR atualizado_em > :since_updated
             ORDER BY atualizado_em
             LIMIT :limit',
            [
                'since_created' => $sinceFormatted,
                'since_updated' => $sinceFormatted,
                'limit' => $limit,
            ],
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'cidade_id' => (int) $row['cidade_id'],
                'nome' => $row['nome'],
                'criado_em' => $this->formatDateTime($row['criado_em']),
                'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
            ]
        );

        $escolas = $this->fetchSyncEntities(
            $pdo,
            'SELECT
                e.id,
                e.cidade_id,
                e.bairro_id,
                e.tipo,
                e.nome,
                e.diretor,
                e.endereco,
                e.total_alunos,
                e.panfletagem,
                e.panfletagem_atualizado_em,
                e.panfletagem_usuario_id,
                e.indicadores,
                e.obs,
                e.versao_row,
                e.criado_em,
                e.atualizado_em
             FROM escolas e
             WHERE e.criado_em > :since_created OR e.atualizado_em > :since_updated
             ORDER BY e.atualizado_em
             LIMIT :limit',
            [
                'since_created' => $sinceFormatted,
                'since_updated' => $sinceFormatted,
                'limit' => $limit,
            ],
            function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'cidade_id' => (int) $row['cidade_id'],
                    'bairro_id' => (int) $row['bairro_id'],
                    'tipo' => $row['tipo'],
                    'nome' => $row['nome'],
                    'diretor' => $row['diretor'],
                    'endereco' => $row['endereco'],
                    'total_alunos' => (int) $row['total_alunos'],
                    'panfletagem' => (bool) ((int) $row['panfletagem']),
                    'panfletagem_atualizado_em' => $this->formatDateTime($row['panfletagem_atualizado_em']),
                    'panfletagem_usuario_id' => $row['panfletagem_usuario_id'] !== null ? (int) $row['panfletagem_usuario_id'] : null,
                    'indicadores' => $this->decodeJson($row['indicadores']),
                    'obs' => $row['obs'],
                    'versao_row' => (int) $row['versao_row'],
                    'criado_em' => $this->formatDateTime($row['criado_em']),
                    'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
                ];
            }
        );

        $schoolIds = array_map(static fn (array $row): int => (int) $row['id'], $escolas);
        $periodos = $this->fetchPeriods($schoolIds);
        $etapas = $this->fetchEtapas($schoolIds);

        $escolas = array_map(function (array $row) use ($periodos, $etapas): array {
            $id = (int) $row['id'];
            $row['periodos'] = $periodos[$id] ?? [];
            $row['etapas'] = $etapas[$id] ?? [];

            return $row;
        }, $escolas);

        return [
            'cidades' => $cidades,
            'bairros' => $bairros,
            'escolas' => $escolas,
            'next_since' => $this->getCurrentTimestamp(),
        ];
    }

    public function getCurrentTimestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public function transaction(callable $callback)
    {
        $pdo = $this->requireConnection();
        $manage = !$pdo->inTransaction();

        if ($manage) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($this);
            if ($manage) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($manage && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<int, string>>
     */
    private function fetchPeriods(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pdo = $this->requireConnection();
        $params = [];
        $placeholders = $this->createInPlaceholder('period_school', $ids, $params);

        $statement = $pdo->prepare(
            'SELECT escola_id, periodo
             FROM escola_periodos
             WHERE escola_id IN (' . $placeholders . ')
             ORDER BY periodo'
        );
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['escola_id']][] = $row['periodo'];
        }

        return $result;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, int>>
     */
    private function fetchEtapas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pdo = $this->requireConnection();
        $params = [];
        $placeholders = $this->createInPlaceholder('etapa_school', $ids, $params);

        $statement = $pdo->prepare(
            'SELECT escola_id, etapa, quantidade
             FROM escola_etapas
             WHERE escola_id IN (' . $placeholders . ')'
        );
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['escola_id']][(string) $row['etapa']] = (int) $row['quantidade'];
        }

        return $result;
    }

    private function getPeriodsForSchool(int $schoolId): array
    {
        $pdo = $this->requireConnection();
        $statement = $pdo->prepare('SELECT periodo FROM escola_periodos WHERE escola_id = :id ORDER BY periodo');
        $statement->execute([':id' => $schoolId]);

        return array_map(static fn ($value): string => (string) $value, $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function getEtapasForSchool(int $schoolId): array
    {
        $pdo = $this->requireConnection();
        $statement = $pdo->prepare('SELECT etapa, quantidade FROM escola_etapas WHERE escola_id = :id');
        $statement->execute([':id' => $schoolId]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['etapa']] = (int) $row['quantidade'];
        }

        return $result;
    }

    private function findObservation(int $id): ?array
    {
        $pdo = $this->requireConnection();
        $statement = $pdo->prepare(
            'SELECT
                o.id,
                o.escola_id,
                o.observacao,
                o.usuario_id,
                o.criado_em,
                o.atualizado_em,
                o.removido_em,
                u.nome AS usuario_nome
             FROM escola_observacoes o
             LEFT JOIN usuarios u ON u.id = o.usuario_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'escola_id' => (int) $row['escola_id'],
            'observacao' => $row['observacao'],
            'usuario' => $row['usuario_id'] !== null ? [
                'id' => (int) $row['usuario_id'],
                'nome' => $row['usuario_nome'],
            ] : null,
            'criado_em' => $this->formatDateTime($row['criado_em']),
            'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
            'removido_em' => $this->formatDateTime($row['removido_em']),
        ];
    }

    private function logObservationChange(
        int $observationId,
        string $action,
        ?int $usuarioId,
        ?array $before,
        ?array $after
    ): void {
        $pdo = $this->requireConnection();
        $statement = $pdo->prepare(
            'INSERT INTO escola_observacao_logs (escola_observacao_id, usuario_id, acao, conteudo_antigo, conteudo_novo)
             VALUES (:observacao_id, :usuario_id, :acao, :conteudo_antigo, :conteudo_novo)'
        );

        $statement->execute([
            ':observacao_id' => $observationId,
            ':usuario_id' => $usuarioId,
            ':acao' => $action,
            ':conteudo_antigo' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':conteudo_novo' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<int, mixed> $filters
     * @return array{where: string, params: array<string, mixed>}
     */
    private function buildFilterClause(array $filters, ?string $search): array
    {
        $conditions = [];
        $params = [];

        $cidadeIds = $this->normalizeIntList($filters['cidade_id'] ?? null);
        if ($cidadeIds !== []) {
            $conditions[] = 'e.cidade_id IN (' . $this->createInPlaceholder('cidade', $cidadeIds, $params) . ')';
        }

        $bairroIds = $this->normalizeIntList($filters['bairro_id'] ?? null);
        if ($bairroIds !== []) {
            $conditions[] = 'e.bairro_id IN (' . $this->createInPlaceholder('bairro', $bairroIds, $params) . ')';
        }

        $tipos = $this->normalizeStringList($filters['tipo'] ?? null);
        if ($tipos !== []) {
            $conditions[] = 'e.tipo IN (' . $this->createInPlaceholder('tipo', $tipos, $params) . ')';
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'todos') {
            $conditions[] = $status === 'pendente' ? 'e.panfletagem = 0' : 'e.panfletagem = 1';
        }

        $periodos = $this->normalizeStringList($filters['periodos'] ?? null);
        if ($periodos !== []) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM escola_periodos ep
                WHERE ep.escola_id = e.id
                  AND ep.periodo IN (' . $this->createInPlaceholder('periodo', $periodos, $params) . ')
            )';
        }

        if ($search !== null && trim($search) !== '') {
            $value = trim($search);
            $params['search'] = $value;
            $params['search_like'] = '%' . $value . '%';
            $conditions[] = '
                (
                    MATCH(e.nome, e.diretor, e.endereco) AGAINST (:search IN NATURAL LANGUAGE MODE)
                    OR e.nome LIKE :search_like
                    OR e.diretor LIKE :search_like
                    OR e.endereco LIKE :search_like
                )';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return ['where' => $where, 'params' => $params];
    }

    /**
     * @param array<int, array{field: string, direction: string}> $sort
     */
    private function buildOrderBy(array $sort): string
    {
        $mapping = [
            'nome' => 'e.nome',
            'total_alunos' => 'e.total_alunos',
            'panfletagem' => 'e.panfletagem',
            'cidade' => 'c.nome',
        ];

        if ($sort === []) {
            return ' ORDER BY e.nome ASC';
        }

        $segments = [];
        foreach ($sort as $rule) {
            $field = $rule['field'];
            if (!isset($mapping[$field])) {
                continue;
            }

            $direction = strtoupper($rule['direction']) === 'DESC' ? 'DESC' : 'ASC';
            $segments[] = sprintf('%s %s', $mapping[$field], $direction);
        }

        if ($segments === []) {
            $segments[] = 'e.nome ASC';
        }

        return ' ORDER BY ' . implode(', ', $segments);
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(array<string, mixed>):array<string, mixed> $map
     * @return array<int, array<string, mixed>>
     */
    private function fetchSyncEntities(PDO $pdo, string $sql, array $params, callable $map): array
    {
        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map($map, $rows);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function normalizeFilterInput(array $filters, array $overrides = []): array
    {
        $ignored = [
            'includeBairros' => true,
            'withEscolas' => true,
            'page' => true,
            'pageSize' => true,
            'per_page' => true,
            'orderBy' => true,
            'order_by' => true,
        ];

        $search = null;
        if (isset($filters['search'])) {
            $value = trim((string) $filters['search']);
            $search = $value !== '' ? $value : null;
            unset($filters['search']);
        }

        $normalized = [];

        if (isset($filters['filter']) && is_array($filters['filter'])) {
            foreach ($filters['filter'] as $key => $value) {
                $normalized[$key] = $value;
            }
            unset($filters['filter']);
        }

        foreach ($filters as $key => $value) {
            if (isset($ignored[$key])) {
                continue;
            }

            $normalized[$key] = $value;
        }

        foreach ($overrides as $key => $value) {
            $normalized[$key] = $value;
        }

        return [$normalized, $search];
    }

    private function defaultPeriodTotals(): array
    {
        return array_fill_keys(self::PERIODOS, 0);
    }

    private function defaultEtapaTotals(): array
    {
        return array_fill_keys(self::ETAPAS, 0);
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function fetchPeriodTotals(PDO $pdo, string $where, array $params, string $scope): array
    {
        $column = $scope === 'cidade' ? 'e.cidade_id' : 'e.bairro_id';

        $sql = '
            SELECT
                ' . $column . ' AS scope_id,
                ep2.periodo AS periodo,
                COUNT(DISTINCT ep2.escola_id) AS total
            FROM escola_periodos ep2
            INNER JOIN escolas e ON e.id = ep2.escola_id
        ' . $where . '
            GROUP BY ' . $column . ', ep2.periodo
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totals = [];
        foreach ($rows as $row) {
            $scopeId = (int) $row['scope_id'];
            $periodo = (string) $row['periodo'];
            $total = (int) $row['total'];

            if (!isset($totals[$scopeId])) {
                $totals[$scopeId] = $this->defaultPeriodTotals();
            }

            if (in_array($periodo, self::PERIODOS, true)) {
                $totals[$scopeId][$periodo] = $total;
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function fetchEtapaTotals(PDO $pdo, string $where, array $params, string $scope): array
    {
        $column = $scope === 'cidade' ? 'e.cidade_id' : 'e.bairro_id';

        $sql = '
            SELECT
                ' . $column . ' AS scope_id,
                ee.etapa AS etapa,
                COALESCE(SUM(ee.quantidade), 0) AS total
            FROM escola_etapas ee
            INNER JOIN escolas e ON e.id = ee.escola_id
        ' . $where . '
            GROUP BY ' . $column . ', ee.etapa
        ';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totals = [];
        foreach ($rows as $row) {
            $scopeId = (int) $row['scope_id'];
            $etapa = (string) $row['etapa'];
            $total = (int) $row['total'];

            if (!isset($totals[$scopeId])) {
                $totals[$scopeId] = $this->defaultEtapaTotals();
            }

            if (in_array($etapa, self::ETAPAS, true)) {
                $totals[$scopeId][$etapa] = $total;
            }
        }

        return $totals;
    }

    private function normalizeIntList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = array_filter(array_map(
            static fn ($item): int => (int) $item,
            $items
        ), static fn (int $item): bool => $item > 0);

        return array_values(array_unique($normalized));
    }

    private function normalizeStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $items
        ), static fn (string $item): bool => $item !== '');

        return array_values(array_unique($normalized));
    }

    private function createInPlaceholder(string $prefix, array $values, array &$params): string
    {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $key = sprintf('%s_%d', $prefix, $index);
            $placeholders[] = ':' . $key;
            $params[':' . $key] = $value;
        }

        if ($placeholders === []) {
            return 'NULL';
        }

        return implode(', ', $placeholders);
    }

    private function bindParameters(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $placeholder = $key;
            if (!str_starts_with($placeholder, ':')) {
                $placeholder = ':' . $placeholder;
            }

            if ($value === null) {
                $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            if (is_int($value)) {
                $statement->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if (is_bool($value)) {
                $statement->bindValue($placeholder, $value ? 1 : 0, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }

    private function decodeJson(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function parseTimestamp(string $timestamp): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            return new DateTimeImmutable('1970-01-01T00:00:00Z');
        }
    }

    private function requireConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        return $this->connection;
    }
}
