<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\PasswordRepositoryInterface;
use PDO;
use PDOStatement;
use RuntimeException;

final class PdoPasswordRepository implements PasswordRepositoryInterface
{
    private const SELECT_COLUMNS = 'p.id, p.usuario, p.senha, p.link, p.tipo, p.local, p.verificado, p.ativo, p.descricao, p.ip, p.created_at, p.updated_at, p.created_by, p.updated_by';

    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function search(
        array $filters,
        ?string $search,
        int $page,
        int $perPage,
        bool $fetchAll,
        array $sort
    ): array {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, $search);
        $where = $clause['where'];
        $params = $clause['params'];

        $orderBy = $this->buildOrderBy($sort);

        $limitFragment = '';
        if (!$fetchAll) {
            $limitFragment = ' LIMIT :limit OFFSET :offset';
        }

        $sql = sprintf('SELECT %s FROM passwords p%s%s', self::SELECT_COLUMNS, $where, $orderBy);
        $statement = $pdo->prepare($sql . $limitFragment);
        $this->bindParameters($statement, $params);

        if (!$fetchAll) {
            $offset = max(0, ($page - 1) * $perPage);
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countSql = sprintf('SELECT COUNT(*) AS total, MAX(updated_at) AS max_updated_at FROM passwords p%s', $where);
        $countStatement = $pdo->prepare($countSql);
        $this->bindParameters($countStatement, $params);
        $countStatement->execute();
        $countRow = $countStatement->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'max_updated_at' => null];

        $normalizedItems = array_map([$this, 'normalizeRow'], $items);

        return [
            'items' => $normalizedItems,
            'total' => (int) ($countRow['total'] ?? 0),
            'max_updated_at' => $countRow['max_updated_at'] ?? null,
        ];
    }

    public function findById(string $id): ?array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(sprintf('SELECT %s FROM passwords p WHERE p.id = :id LIMIT 1', self::SELECT_COLUMNS));
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    public function findByLocalAndUsuario(string $local, string $usuario): ?array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            sprintf(
                'SELECT %s FROM passwords p WHERE p.local = :local AND p.usuario = :usuario AND p.ativo = 1 LIMIT 1',
                self::SELECT_COLUMNS
            )
        );
        $statement->execute([
            ':local' => $local,
            ':usuario' => $usuario,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    public function create(array $data): array
    {
        $pdo = $this->requireConnection();

        $id = $this->uuid();

        $statement = $pdo->prepare(
            'INSERT INTO passwords (
                id, usuario, senha, link, tipo, local, verificado, ativo, descricao, ip, created_at, updated_at, created_by, updated_by
            ) VALUES (
                :id, :usuario, :senha, :link, :tipo, :local, :verificado, :ativo, :descricao, :ip, :created_at, :updated_at, :created_by, :updated_by
            )'
        );

        $statement->execute([
            ':id' => $id,
            ':usuario' => $data['usuario'],
            ':senha' => $data['senha'],
            ':link' => $data['link'],
            ':tipo' => $data['tipo'],
            ':local' => $data['local'],
            ':verificado' => (int) ($data['verificado'] ?? 0),
            ':ativo' => (int) ($data['ativo'] ?? 1),
            ':descricao' => $data['descricao'],
            ':ip' => $data['ip'],
            ':created_at' => $data['created_at'] ?? null,
            ':updated_at' => $data['updated_at'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
            ':updated_by' => $data['updated_by'] ?? null,
        ]);

        return $this->findById($id) ?? [];
    }

    public function update(string $id, array $data): array
    {
        $pdo = $this->requireConnection();

        $fields = [];
        $params = [':id' => $id];

        foreach ([
            'usuario',
            'senha',
            'link',
            'tipo',
            'local',
            'verificado',
            'ativo',
            'descricao',
            'ip',
            'updated_at',
            'updated_by',
        ] as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $fields[] = sprintf('%s = :%s', $column, $column);
            $value = $data[$column];

            if ($column === 'verificado' || $column === 'ativo') {
                $value = (int) $value;
            }

            $params[sprintf(':%s', $column)] = $value;
        }

        if ($fields === []) {
            return $this->findById($id) ?? [];
        }

        $sql = sprintf('UPDATE passwords SET %s WHERE id = :id', implode(', ', $fields));
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $this->findById($id) ?? [];
    }

    public function softDelete(string $id, ?string $userId = null): bool
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'UPDATE passwords
             SET ativo = 0, updated_at = NOW(), updated_by = :updated_by
             WHERE id = :id'
        );

        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->bindValue(':updated_by', $userId);

        $statement->execute();

        return $statement->rowCount() > 0;
    }

    public function bulkUpdate(array $ids, array $data): array
    {
        if ($ids === []) {
            return ['affected' => 0];
        }

        $pdo = $this->requireConnection();

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $fields = [];
        foreach (['verificado', 'ativo', 'updated_at', 'updated_by'] as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $fields[] = sprintf('%s = :%s', $column, $column);
        }

        if ($fields === []) {
            return ['affected' => 0];
        }

        $sql = sprintf(
            'UPDATE passwords SET %s WHERE id IN (%s)',
            implode(', ', $fields),
            implode(', ', $placeholders)
        );

        $statement = $pdo->prepare($sql);

        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value, PDO::PARAM_STR);
        }

        foreach (['verificado', 'ativo', 'updated_at', 'updated_by'] as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $value = $data[$column];
            if ($column === 'verificado' || $column === 'ativo') {
                $value = (int) $value;
            }

            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        return ['affected' => $statement->rowCount()];
    }

    public function bulkDelete(array $ids): array
    {
        if ($ids === []) {
            return ['affected' => 0];
        }

        $pdo = $this->requireConnection();

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $sql = sprintf('UPDATE passwords SET ativo = 0, updated_at = NOW() WHERE id IN (%s)', implode(', ', $placeholders));
        $statement = $pdo->prepare($sql);

        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value, PDO::PARAM_STR);
        }

        $statement->execute();

        return ['affected' => $statement->rowCount()];
    }

    public function stats(array $filters): array
    {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, null);
        $where = $clause['where'];
        $params = $clause['params'];

        $totalsSql = sprintf(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN verificado = 1 THEN 1 ELSE 0 END) AS verificados,
                SUM(CASE WHEN verificado = 0 THEN 1 ELSE 0 END) AS nao_verificados,
                SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS criadas_hoje,
                SUM(CASE WHEN DATE(updated_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS atualizadas_hoje
             FROM passwords p%s',
            $where
        );

        $totalsStmt = $pdo->prepare($totalsSql);
        $this->bindParameters($totalsStmt, $params);
        $totalsStmt->execute();
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $tipoSql = sprintf('SELECT tipo, COUNT(*) AS count FROM passwords p%s GROUP BY tipo', $where);
        $tipoStmt = $pdo->prepare($tipoSql);
        $this->bindParameters($tipoStmt, $params);
        $tipoStmt->execute();
        $tipoRows = $tipoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $plataformaSql = sprintf('SELECT local, COUNT(*) AS count FROM passwords p%s GROUP BY local ORDER BY count DESC', $where);
        $plataformaStmt = $pdo->prepare($plataformaSql);
        $this->bindParameters($plataformaStmt, $params);
        $plataformaStmt->execute();
        $plataformaRows = $plataformaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ultimasSql = sprintf(
            'SELECT id, usuario, local, updated_at FROM passwords p%s ORDER BY updated_at DESC LIMIT 5',
            $where
        );
        $ultimasStmt = $pdo->prepare($ultimasSql);
        $this->bindParameters($ultimasStmt, $params);
        $ultimasStmt->execute();
        $ultimasRows = $ultimasStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($totals['total'] ?? 0),
            'verificados' => (int) ($totals['verificados'] ?? 0),
            'nao_verificados' => (int) ($totals['nao_verificados'] ?? 0),
            'por_tipo' => $this->mapKeyCount($tipoRows, 'tipo'),
            'por_plataforma' => $this->mapKeyCount($plataformaRows, 'local'),
            'ultimas_atualizacoes' => array_map(
                static fn (array $row): array => [
                    'id' => $row['id'],
                    'usuario' => $row['usuario'],
                    'local' => $row['local'],
                    'updated_at' => $row['updated_at'],
                ],
                $ultimasRows
            ),
            'criadas_hoje' => (int) ($totals['criadas_hoje'] ?? 0),
            'atualizadas_hoje' => (int) ($totals['atualizadas_hoje'] ?? 0),
        ];
    }

    public function platforms(array $filters, int $limit, ?int $minCount): array
    {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, null);
        $where = $clause['where'];
        $params = $clause['params'];

        $sql = sprintf(
            'SELECT local, COUNT(*) AS count FROM passwords p%s GROUP BY local HAVING count >= :min_count ORDER BY count DESC LIMIT :limit',
            $where
        );

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->bindValue(':min_count', $minCount ?? 1, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit > 0 ? $limit : 20, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(
            static fn (array $row): array => [
                'local' => $row['local'],
                'count' => (int) $row['count'],
            ],
            $rows
        );
    }

    public function export(array $filters, ?string $search, array $sort): iterable
    {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, $search);
        $where = $clause['where'];
        $params = $clause['params'];
        $orderBy = $this->buildOrderBy($sort);

        $sql = sprintf('SELECT %s FROM passwords p%s%s', self::SELECT_COLUMNS, $where, $orderBy);
        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            yield $this->normalizeRow($row);
        }
    }

    public function logAction(
        string $passwordId,
        string $action,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent,
        array $before = [],
        array $after = []
    ): void {
        if ($passwordId === '') {
            return;
        }

        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO password_logs (
                id, password_id, acao, usuario_id, ip_origem, user_agent, dados_antes, dados_depois, created_at
            ) VALUES (
                :id, :password_id, :acao, :usuario_id, :ip_origem, :user_agent, :dados_antes, :dados_depois, NOW()
            )'
        );

        $statement->execute([
            ':id' => $this->uuid(),
            ':password_id' => $passwordId,
            ':acao' => $action,
            ':usuario_id' => $userId,
            ':ip_origem' => $originIp,
            ':user_agent' => $userAgent,
            ':dados_antes' => $before === [] ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':dados_depois' => $after === [] ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{where: string, params: array<string, mixed>}
     */
    private function buildFilterClause(array $filters, ?string $search): array
    {
        $conditions = [];
        $params = [];

        if (!array_key_exists('include_inactive', $filters)) {
            $conditions[] = 'p.ativo = 1';
        } elseif (!$filters['include_inactive']) {
            $conditions[] = 'p.ativo = 1';
        }

        if (isset($filters['ativo'])) {
            $conditions[] = 'p.ativo = :ativo';
            $params['ativo'] = (int) $filters['ativo'];
        }

        if (isset($filters['tipo'])) {
            $conditions[] = 'p.tipo = :tipo';
            $params['tipo'] = $filters['tipo'];
        }

        if (isset($filters['local'])) {
            $conditions[] = 'p.local LIKE :local';
            $params['local'] = '%' . $filters['local'] . '%';
        }

        if (isset($filters['verificado'])) {
            $conditions[] = 'p.verificado = :verificado';
            $params['verificado'] = (int) $filters['verificado'];
        }

        if (isset($filters['created_at_gte'])) {
            $conditions[] = 'p.created_at >= :created_at_gte';
            $params['created_at_gte'] = $filters['created_at_gte'];
        }

        if (isset($filters['created_at_lte'])) {
            $conditions[] = 'p.created_at <= :created_at_lte';
            $params['created_at_lte'] = $filters['created_at_lte'];
        }

        if (isset($filters['updated_at_gte'])) {
            $conditions[] = 'p.updated_at >= :updated_at_gte';
            $params['updated_at_gte'] = $filters['updated_at_gte'];
        }

        if (isset($filters['updated_at_lte'])) {
            $conditions[] = 'p.updated_at <= :updated_at_lte';
            $params['updated_at_lte'] = $filters['updated_at_lte'];
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(p.usuario LIKE :search OR p.local LIKE :search OR p.link LIKE :search OR p.descricao LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    /**
     * @param array<int, array{field: string, direction: string}> $sort
     */
    private function buildOrderBy(array $sort): string
    {
        if ($sort === []) {
            return ' ORDER BY p.updated_at DESC';
        }

        $allowed = [
            'id' => 'p.id',
            'usuario' => 'p.usuario',
            'local' => 'p.local',
            'tipo' => 'p.tipo',
            'verificado' => 'p.verificado',
            'created_at' => 'p.created_at',
            'updated_at' => 'p.updated_at',
        ];

        $segments = [];
        foreach ($sort as $rule) {
            $field = $rule['field'];
            if (!isset($allowed[$field])) {
                continue;
            }

            $direction = strtoupper($rule['direction']) === 'DESC' ? 'DESC' : 'ASC';
            $segments[] = sprintf('%s %s', $allowed[$field], $direction);
        }

        if ($segments === []) {
            $segments[] = 'p.updated_at DESC';
        }

        return ' ORDER BY ' . implode(', ', $segments);
    }

    private function bindParameters(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $placeholder = str_starts_with($key, ':') ? $key : ':' . $key;

            if ($value === null) {
                $statement->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            if (is_int($value)) {
                $statement->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }

    /**
     * @return array<string, int>
     */
    private function mapKeyCount(array $rows, string $keyColumn): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!isset($row[$keyColumn])) {
                continue;
            }
            $result[(string) $row[$keyColumn]] = (int) ($row['count'] ?? 0);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        if (array_key_exists('verificado', $row)) {
            $row['verificado'] = (bool) ((int) $row['verificado']);
        }
        if (array_key_exists('ativo', $row)) {
            $row['ativo'] = (bool) ((int) $row['ativo']);
        }

        return $row;
    }

    private function requireConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        return $this->connection;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

