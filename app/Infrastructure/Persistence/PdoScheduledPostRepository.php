<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\ScheduledPostRepositoryInterface;
use PDO;
use RuntimeException;

final class PdoScheduledPostRepository implements ScheduledPostRepositoryInterface
{
    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $columns = 'id, type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at';
        $sql = sprintf('SELECT %s FROM scheduled_posts', $columns);
        $conditions = [];
        $params = [];

        if (isset($filters['type'])) {
            $conditions[] = 'type = :type';
            $params['type'] = $filters['type'];
        }

        if (isset($filters['scheduled_datetime_gte'])) {
            $conditions[] = 'scheduled_datetime >= :scheduled_gte';
            $params['scheduled_gte'] = $filters['scheduled_datetime_gte'];
        }

        if (isset($filters['scheduled_datetime_lte'])) {
            $conditions[] = 'scheduled_datetime <= :scheduled_lte';
            $params['scheduled_lte'] = $filters['scheduled_datetime_lte'];
        }

        if (isset($filters['message_id_state'])) {
            if ($filters['message_id_state'] === 'null') {
                $conditions[] = 'messageId IS NULL';
            } elseif ($filters['message_id_state'] === 'not_null') {
                $conditions[] = 'messageId IS NOT NULL';
            }
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(message LIKE :search_text OR caption LIKE :search_text)';
            $params['search_text'] = '%' . $search . '%';
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

        $countSql = 'SELECT COUNT(*) AS total, MAX(updated_at) AS max_updated_at FROM scheduled_posts';
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
        $countData = $countStatement->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'max_updated_at' => null];

        $normalizedItems = array_map([$this, 'normalizeRow'], $items ?: []);

        return [
            'items' => $normalizedItems,
            'total' => (int) ($countData['total'] ?? 0),
            'max_updated_at' => $countData['max_updated_at'] ?? null,
        ];
    }

    public function findById(int $id): ?array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare('SELECT id, type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at FROM scheduled_posts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        return $result === null ? null : $this->normalizeRow($result);
    }

    public function create(array $payload): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO scheduled_posts (type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at)
             VALUES (:type, :message, :image_url, :video_url, :caption, :scheduled_datetime, :zaapId, :messageId, NOW(), NOW())'
        );

        $statement->execute([
            'type' => $payload['type'],
            'message' => $payload['message'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
            'video_url' => $payload['video_url'] ?? null,
            'caption' => $payload['caption'] ?? null,
            'scheduled_datetime' => $payload['scheduled_datetime'],
            'zaapId' => $payload['zaapId'] ?? null,
            'messageId' => $payload['messageId'] ?? null,
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

        foreach (['type', 'message', 'image_url', 'video_url', 'caption', 'scheduled_datetime', 'zaapId', 'messageId'] as $column) {
            if (array_key_exists($column, $payload)) {
                $fields[] = sprintf('%s = :%s', $column, $column);
                $params[$column] = $payload[$column];
            }
        }

        if ($fields === []) {
            return $this->findById($id);
        }

        $fields[] = 'updated_at = NOW()';
        $sql = sprintf('UPDATE scheduled_posts SET %s WHERE id = :id', implode(', ', $fields));
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare('DELETE FROM scheduled_posts WHERE id = :id');

        return $statement->execute(['id' => $id]);
    }

    public function findReady(int $limit): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare(
            'SELECT id, type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at
             FROM scheduled_posts
             WHERE scheduled_datetime <= NOW() AND (messageId IS NULL OR messageId = \'\')
             ORDER BY scheduled_datetime ASC
             LIMIT :limit'
        );

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $items ?: []);
    }

    public function markSent(int $id, array $payload): ?array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $statement = $this->connection->prepare(
            'UPDATE scheduled_posts
             SET zaapId = :zaapId, messageId = :messageId, updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'zaapId' => $payload['zaapId'] ?? null,
            'messageId' => $payload['messageId'] ?? null,
        ]);

        return $this->findById($id);
    }

    private function buildOrderBy(array $sort): string
    {
        if ($sort === []) {
            return 'ORDER BY scheduled_datetime ASC';
        }

        $allowed = [
            'scheduled_datetime' => 'scheduled_datetime',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'id' => 'id',
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
            $segments[] = 'scheduled_datetime ASC';
        }

        return 'ORDER BY ' . implode(', ', $segments);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row['has_media'] = ($row['image_url'] ?? null) !== null || ($row['video_url'] ?? null) !== null;

        return $row;
    }
}
