<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\ScheduledPostRepositoryInterface;
use PDO;
use PDOStatement;
use RuntimeException;

final class PdoScheduledPostRepository implements ScheduledPostRepositoryInterface
{
    private const SELECT_COLUMNS = 'id, type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at';
    private const FAILED_GRACE_MINUTES = 10;
    private const MESSAGE_SENT_CONDITION = "(messageId IS NOT NULL AND messageId <> '')";
    private const MESSAGE_NOT_SENT_CONDITION = "(messageId IS NULL OR messageId = '')";

    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function search(array $filters, ?string $search, int $page, int $perPage, bool $fetchAll, array $sort): array
    {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, $search);
        $where = $clause['where'];
        $params = $clause['params'];

        $sql = sprintf('SELECT %s FROM scheduled_posts%s', self::SELECT_COLUMNS, $where);

        $orderBy = $this->buildOrderBy($sort);
        if ($orderBy !== '') {
            $sql .= ' ' . $orderBy;
        }

        $limitFragment = '';
        if (!$fetchAll) {
            $limitFragment = ' LIMIT :limit OFFSET :offset';
        }

        $statement = $pdo->prepare($sql . $limitFragment);
        $this->bindParameters($statement, $params);

        if (!$fetchAll) {
            $offset = max(0, ($page - 1) * $perPage);
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countSql = sprintf('SELECT COUNT(*) AS total, MAX(updated_at) AS max_updated_at FROM scheduled_posts%s', $where);
        $countStatement = $pdo->prepare($countSql);
        $this->bindParameters($countStatement, $params);
        $countStatement->execute();
        $countData = $countStatement->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'max_updated_at' => null];

        $normalizedItems = array_map([$this, 'normalizeRow'], $items);

        return [
            'items' => $normalizedItems,
            'total' => (int) ($countData['total'] ?? 0),
            'max_updated_at' => $countData['max_updated_at'] ?? null,
        ];
    }

    public function findById(int $id): ?array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            sprintf('SELECT %s FROM scheduled_posts WHERE id = :id LIMIT 1', self::SELECT_COLUMNS)
        );
        $statement->execute([':id' => $id]);
        $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        return $result === null ? null : $this->normalizeRow($result);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pdo = $this->requireConnection();

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $id;
        }

        $statement = $pdo->prepare(
            sprintf('SELECT %s FROM scheduled_posts WHERE id IN (%s)', self::SELECT_COLUMNS, implode(', ', $placeholders))
        );

        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value, PDO::PARAM_INT);
        }

        $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalizeRow'], $items);
    }

    public function create(array $payload): array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO scheduled_posts (type, message, image_url, video_url, caption, scheduled_datetime, zaapId, messageId, created_at, updated_at)
             VALUES (:type, :message, :image_url, :video_url, :caption, :scheduled_datetime, :zaapId, :messageId, NOW(), NOW())'
        );

        $statement->execute([
            ':type' => $payload['type'],
            ':message' => $payload['message'] ?? null,
            ':image_url' => $payload['image_url'] ?? null,
            ':video_url' => $payload['video_url'] ?? null,
            ':caption' => $payload['caption'] ?? null,
            ':scheduled_datetime' => $payload['scheduled_datetime'],
            ':zaapId' => $payload['zaapId'] ?? null,
            ':messageId' => $payload['messageId'] ?? null,
        ]);

        $id = (int) $pdo->lastInsertId();

        return $this->findById($id) ?? [];
    }

    public function update(int $id, array $payload): ?array
    {
        $pdo = $this->requireConnection();

        $fields = [];
        $params = [':id' => $id];

        foreach (['type', 'message', 'image_url', 'video_url', 'caption', 'scheduled_datetime', 'zaapId', 'messageId'] as $column) {
            if (array_key_exists($column, $payload)) {
                $fields[] = sprintf('%s = :%s', $column, $column);
                $params[':' . $column] = $payload[$column];
            }
        }

        if ($fields === []) {
            return $this->findById($id);
        }

        $fields[] = 'updated_at = NOW()';
        $sql = sprintf('UPDATE scheduled_posts SET %s WHERE id = :id', implode(', ', $fields));
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare('DELETE FROM scheduled_posts WHERE id = :id');

        return $statement->execute([':id' => $id]);
    }

    public function findReady(int $limit): array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            sprintf(
                'SELECT %s
                 FROM scheduled_posts
                 WHERE scheduled_datetime <= NOW() AND %s
                 ORDER BY scheduled_datetime ASC
                 LIMIT :limit',
                self::SELECT_COLUMNS,
                self::MESSAGE_NOT_SENT_CONDITION
            )
        );

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalizeRow'], $items);
    }

    public function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $pdo = $this->requireConnection();

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $id;
        }

        $sql = sprintf('DELETE FROM scheduled_posts WHERE id IN (%s)', implode(', ', $placeholders));
        $statement = $pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $statement->bindValue($placeholder, $value, PDO::PARAM_INT);
        }

        $statement->execute();

        return $statement->rowCount();
    }

    public function updateMany(array $ids, array $payload): int
    {
        if ($ids === [] || $payload === []) {
            return 0;
        }

        $pdo = $this->requireConnection();

        $fields = [];
        $params = [];
        foreach ($payload as $column => $value) {
            $fields[] = sprintf('%s = :%s', $column, $column);
            $params[$column] = $value;
        }

        $idPlaceholders = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholder = 'id' . $index;
            $idPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $id;
        }

        $fields[] = 'updated_at = NOW()';
        $sql = sprintf(
            'UPDATE scheduled_posts SET %s WHERE id IN (%s)',
            implode(', ', $fields),
            implode(', ', $idPlaceholders)
        );

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->execute();

        return $statement->rowCount();
    }

    public function analytics(array $filters, ?string $search): array
    {
        $pdo = $this->requireConnection();
        $clause = $this->buildFilterClause($filters, $search);
        $where = $clause['where'];
        $params = $clause['params'];

        $summarySql = sprintf(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN %1$s THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN %2$s AND scheduled_datetime <= NOW() AND scheduled_datetime >= DATE_SUB(NOW(), INTERVAL %3$d MINUTE) THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN %2$s AND scheduled_datetime > NOW() THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN %2$s AND scheduled_datetime < DATE_SUB(NOW(), INTERVAL %3$d MINUTE) THEN 1 ELSE 0 END) AS failed
             FROM scheduled_posts%4$s',
            self::MESSAGE_SENT_CONDITION,
            self::MESSAGE_NOT_SENT_CONDITION,
            self::FAILED_GRACE_MINUTES,
            $where
        );

        $summaryStatement = $pdo->prepare($summarySql);
        $this->bindParameters($summaryStatement, $params);
        $summaryStatement->execute();
        $summaryRow = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($summaryRow['total'] ?? 0);
        $sent = (int) ($summaryRow['sent'] ?? 0);
        $pending = (int) ($summaryRow['pending'] ?? 0);
        $scheduled = (int) ($summaryRow['scheduled'] ?? 0);
        $failed = (int) ($summaryRow['failed'] ?? 0);

        $successRate = $total > 0 ? round(($sent / $total) * 100, 1) : 0.0;

        $byTypeSql = sprintf(
            'SELECT type, COUNT(*) AS total FROM scheduled_posts%s GROUP BY type',
            $where
        );
        $byTypeStatement = $pdo->prepare($byTypeSql);
        $this->bindParameters($byTypeStatement, $params);
        $byTypeStatement->execute();
        $byTypeRows = $byTypeStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byType = [
            'text' => 0,
            'image' => 0,
            'video' => 0,
        ];
        foreach ($byTypeRows as $row) {
            $type = $row['type'] ?? null;
            if ($type !== null && array_key_exists($type, $byType)) {
                $byType[$type] = (int) ($row['total'] ?? 0);
            }
        }

        $byDateSql = sprintf(
            'SELECT
                DATE(scheduled_datetime) AS bucket_date,
                SUM(CASE WHEN %1$s THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN %2$s AND scheduled_datetime > NOW() THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN %2$s AND scheduled_datetime < DATE_SUB(NOW(), INTERVAL %3$d MINUTE) THEN 1 ELSE 0 END) AS failed
             FROM scheduled_posts%4$s
             GROUP BY bucket_date
             ORDER BY bucket_date DESC
             LIMIT 30',
            self::MESSAGE_SENT_CONDITION,
            self::MESSAGE_NOT_SENT_CONDITION,
            self::FAILED_GRACE_MINUTES,
            $where
        );
        $byDateStatement = $pdo->prepare($byDateSql);
        $this->bindParameters($byDateStatement, $params);
        $byDateStatement->execute();
        $byDateRows = $byDateStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byDate = array_map(
            static fn (array $row): array => [
                'date' => $row['bucket_date'] ?? null,
                'sent' => (int) ($row['sent'] ?? 0),
                'scheduled' => (int) ($row['scheduled'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ],
            $byDateRows
        );

        $recentSql = sprintf(
            'SELECT
                MAX(CASE WHEN %1$s THEN updated_at ELSE NULL END) AS last_sent,
                MAX(created_at) AS last_created,
                SUM(CASE WHEN %1$s AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) AS sent_last_30min,
                SUM(CASE WHEN %1$s AND DATE(updated_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS sent_today
             FROM scheduled_posts%2$s',
            self::MESSAGE_SENT_CONDITION,
            $where
        );
        $recentStatement = $pdo->prepare($recentSql);
        $this->bindParameters($recentStatement, $params);
        $recentStatement->execute();
        $recentRow = $recentStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $upcomingSql = sprintf(
            'SELECT
                SUM(CASE WHEN %1$s AND scheduled_datetime > NOW() AND scheduled_datetime <= DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS next_hour,
                SUM(CASE WHEN %1$s AND scheduled_datetime > NOW() AND scheduled_datetime <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS next_24h,
                SUM(CASE WHEN %1$s AND scheduled_datetime > NOW() AND scheduled_datetime <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS next_7days
             FROM scheduled_posts%2$s',
            self::MESSAGE_NOT_SENT_CONDITION,
            $where
        );
        $upcomingStatement = $pdo->prepare($upcomingSql);
        $this->bindParameters($upcomingStatement, $params);
        $upcomingStatement->execute();
        $upcomingRow = $upcomingStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $performanceSql = sprintf(
            'SELECT
                AVG(CASE WHEN %1$s THEN GREATEST(TIMESTAMPDIFF(SECOND, scheduled_datetime, updated_at), 0) END) AS avg_delivery,
                AVG(CASE WHEN %1$s THEN GREATEST(TIMESTAMPDIFF(SECOND, created_at, updated_at), 0) END) AS avg_processing
             FROM scheduled_posts%2$s',
            self::MESSAGE_SENT_CONDITION,
            $where
        );
        $performanceStatement = $pdo->prepare($performanceSql);
        $this->bindParameters($performanceStatement, $params);
        $performanceStatement->execute();
        $performanceRow = $performanceStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'summary' => [
                'total' => $total,
                'sent' => $sent,
                'pending' => $pending,
                'scheduled' => $scheduled,
                'failed' => $failed,
            ],
            'success_rate' => $successRate,
            'by_type' => $byType,
            'by_date' => $byDate,
            'recent_activity' => [
                'last_sent' => $recentRow['last_sent'] ?? null,
                'last_created' => $recentRow['last_created'] ?? null,
                'sent_last_30min' => (int) ($recentRow['sent_last_30min'] ?? 0),
                'sent_today' => (int) ($recentRow['sent_today'] ?? 0),
            ],
            'upcoming' => [
                'next_hour' => (int) ($upcomingRow['next_hour'] ?? 0),
                'next_24h' => (int) ($upcomingRow['next_24h'] ?? 0),
                'next_7days' => (int) ($upcomingRow['next_7days'] ?? 0),
            ],
            'performance' => [
                'avg_delivery_time_seconds' => $performanceRow['avg_delivery'] !== null ? (float) $performanceRow['avg_delivery'] : null,
                'avg_processing_time_seconds' => $performanceRow['avg_processing'] !== null ? (float) $performanceRow['avg_processing'] : null,
            ],
        ];
    }

    public function markSent(int $id, array $payload): ?array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'UPDATE scheduled_posts
             SET zaapId = :zaapId, messageId = :messageId, updated_at = NOW()
             WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':zaapId' => $payload['zaapId'] ?? null,
            ':messageId' => $payload['messageId'] ?? null,
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
            'type' => 'type',
        ];

        $segments = [];
        foreach ($sort as $rule) {
            $field = $rule['field'] ?? null;
            if (!is_string($field) || !isset($allowed[$field])) {
                continue;
            }

            $direction = strtoupper($rule['direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
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
        $image = $row['image_url'] ?? null;
        $video = $row['video_url'] ?? null;
        $row['has_media'] = ($image !== null && $image !== '') || ($video !== null && $video !== '');

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{where: string, params: array<string, mixed>}
     */
    private function buildFilterClause(array $filters, ?string $search): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
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

        if (isset($filters['created_at_gte'])) {
            $conditions[] = 'created_at >= :created_gte';
            $params['created_gte'] = $filters['created_at_gte'];
        }

        if (isset($filters['created_at_lte'])) {
            $conditions[] = 'created_at <= :created_lte';
            $params['created_lte'] = $filters['created_at_lte'];
        }

        if (!empty($filters['scheduled_today'])) {
            $conditions[] = 'DATE(scheduled_datetime) = CURRENT_DATE';
        }

        if (!empty($filters['scheduled_this_week'])) {
            $conditions[] = 'YEARWEEK(scheduled_datetime, 1) = YEARWEEK(CURDATE(), 1)';
        }

        if (array_key_exists('has_media', $filters)) {
            $hasMedia = $filters['has_media'];
            if ($hasMedia === true || $hasMedia === 'true' || $hasMedia === 1) {
                $conditions[] = '((image_url IS NOT NULL AND image_url <> \'\') OR (video_url IS NOT NULL AND video_url <> \'\'))';
            } elseif ($hasMedia === false || $hasMedia === 'false' || $hasMedia === 0) {
                $conditions[] = '((image_url IS NULL OR image_url = \'\') AND (video_url IS NULL OR video_url = \'\'))';
            }
        }

        if (isset($filters['caption_contains']) && $filters['caption_contains'] !== '') {
            $conditions[] = 'caption LIKE :caption_contains';
            $params['caption_contains'] = '%' . $filters['caption_contains'] . '%';
        }

        if (isset($filters['message_id_state'])) {
            if ($filters['message_id_state'] === 'null') {
                $conditions[] = self::MESSAGE_NOT_SENT_CONDITION;
            } elseif ($filters['message_id_state'] === 'not_null') {
                $conditions[] = self::MESSAGE_SENT_CONDITION;
            }
        }

        if (isset($filters['status']) && is_string($filters['status'])) {
            $statusCondition = $this->buildStatusCondition($filters['status']);
            if ($statusCondition !== null) {
                $conditions[] = $statusCondition;
            }
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(message LIKE :search_text OR caption LIKE :search_text)';
            $params['search_text'] = '%' . $search . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    private function buildStatusCondition(string $status): ?string
    {
        return match (strtolower($status)) {
            'sent' => self::MESSAGE_SENT_CONDITION,
            'pending' => sprintf('%s AND scheduled_datetime <= NOW() AND scheduled_datetime >= DATE_SUB(NOW(), INTERVAL %d MINUTE)', self::MESSAGE_NOT_SENT_CONDITION, self::FAILED_GRACE_MINUTES),
            'scheduled' => sprintf('%s AND scheduled_datetime > NOW()', self::MESSAGE_NOT_SENT_CONDITION),
            'failed' => sprintf('%s AND scheduled_datetime < DATE_SUB(NOW(), INTERVAL %d MINUTE)', self::MESSAGE_NOT_SENT_CONDITION, self::FAILED_GRACE_MINUTES),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
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

    private function requireConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        return $this->connection;
    }
}
