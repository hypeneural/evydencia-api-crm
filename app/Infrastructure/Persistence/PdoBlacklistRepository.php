<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\BlacklistRepositoryInterface;
use PDO;

use RuntimeException;

final class PdoBlacklistRepository implements BlacklistRepositoryInterface
{
    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $columns = 'id, name, whatsapp, has_closed_order, observation, created_at, updated_at';
        $sql = sprintf('SELECT %s FROM whatsapp_blacklist', $columns);
        $conditions = [];
        $params = [];

        if (isset($filters['whatsapp'])) {
            $conditions[] = 'whatsapp = :whatsapp';
            $params['whatsapp'] = $filters['whatsapp'];
        }

        if (isset($filters['name_like'])) {
            $conditions[] = 'LOWER(name) LIKE :name_like';
            $params['name_like'] = '%' . strtolower((string) $filters['name_like']) . '%';
        }

        if (array_key_exists('has_closed_order', $filters)) {
            $conditions[] = 'has_closed_order = :has_closed_order';
            $params['has_closed_order'] = (int) $filters['has_closed_order'];
        }

        if (isset($filters['created_at_gte'])) {
            $conditions[] = 'created_at >= :created_at_gte';
            $params['created_at_gte'] = $filters['created_at_gte'];
        }

        if (isset($filters['created_at_lte'])) {
            $conditions[] = 'created_at <= :created_at_lte';
            $params['created_at_lte'] = $filters['created_at_lte'];
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(LOWER(name) LIKE :search_name OR whatsapp LIKE :search_whatsapp)';
            $params['search_name'] = '%' . strtolower($search) . '%';
            $params['search_whatsapp'] = '%' . $this->digitsOnly($search) . '%';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $orderBy = $this->buildOrderBy($sort);
        if ($orderBy !== '') {
            $sql .= ' ' . $orderBy;
        }

        $limit = '';
        if (!$fetchAll) {
            $offset = max(0, ($page - 1) * $perPage);
            $limit = ' LIMIT :limit OFFSET :offset';
        }

        $statement = $this->connection->prepare($sql . $limit);

        foreach ($params as $key => $value) {
            $parameter = ':' . $key;
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue($parameter, $value, $type);
        }

        if (!$fetchAll) {
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);

        $countSql = 'SELECT COUNT(*) FROM whatsapp_blacklist';
        if ($conditions !== []) {
            $countSql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $countStatement = $this->connection->prepare($countSql);
        foreach ($params as $key => $value) {
            $parameter = ':' . $key;
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStatement->bindValue($parameter, $value, $type);
        }
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $normalizedItems = array_map(static function (array $row): array {
            $row['has_closed_order'] = (bool) ((int) ($row['has_closed_order'] ?? 0));

            return $row;
        }, $items ?: []);

        return [
            'items' => $normalizedItems,
            'total' => $total,
        ];
    }

    public function findById(int $id): ?array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare('SELECT id, name, whatsapp, has_closed_order, observation, created_at, updated_at FROM whatsapp_blacklist WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($result === null) {
            return null;
        }

        $result['has_closed_order'] = (bool) ((int) ($result['has_closed_order'] ?? 0));

        return $result;
    }

    public function findByWhatsapp(string $whatsapp): ?array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare('SELECT id, name, whatsapp, has_closed_order, observation, created_at, updated_at FROM whatsapp_blacklist WHERE whatsapp = :whatsapp LIMIT 1');
        $statement->execute(['whatsapp' => $whatsapp]);
        $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($result === null) {
            return null;
        }

        $result['has_closed_order'] = (bool) ((int) ($result['has_closed_order'] ?? 0));

        return $result;
    }

    public function create(array $payload): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO whatsapp_blacklist (name, whatsapp, has_closed_order, observation, created_at, updated_at)
             VALUES (:name, :whatsapp, :has_closed_order, :observation, NOW(), NOW())'
        );

        $statement->execute([
            'name' => $payload['name'],
            'whatsapp' => $payload['whatsapp'],
            'has_closed_order' => (int) $payload['has_closed_order'],
            'observation' => $payload['observation'],
        ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->findById($id) ?? [];
    }

    public function update(int $id, array $payload): ?array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('name', $payload)) {
            $fields[] = 'name = :name';
            $params['name'] = $payload['name'];
        }

        if (array_key_exists('whatsapp', $payload)) {
            $fields[] = 'whatsapp = :whatsapp';
            $params['whatsapp'] = $payload['whatsapp'];
        }

        if (array_key_exists('has_closed_order', $payload)) {
            $fields[] = 'has_closed_order = :has_closed_order';
            $params['has_closed_order'] = (int) $payload['has_closed_order'];
        }

        if (array_key_exists('observation', $payload)) {
            $fields[] = 'observation = :observation';
            $params['observation'] = $payload['observation'];
        }

        if ($fields === []) {
            return $this->findById($id);
        }

        $fields[] = 'updated_at = NOW()';

        $sql = sprintf('UPDATE whatsapp_blacklist SET %s WHERE id = :id', implode(', ', $fields));
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare('DELETE FROM whatsapp_blacklist WHERE id = :id');

        return $statement->execute(['id' => $id]);
    }

    private function buildOrderBy(array $sort): string
    {
        if ($sort === []) {
            return 'ORDER BY created_at DESC';
        }

        $allowed = [
            'id' => 'id',
            'name' => 'name',
            'whatsapp' => 'whatsapp',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
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
            $segments[] = 'created_at DESC';
        }

        return 'ORDER BY ' . implode(', ', $segments);
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
