<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\OrderRepositoryInterface;
use PDO;
use PDOException;

final class PdoOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function findByUuid(string $uuid): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        try {
            $statement = $this->connection->prepare('SELECT uuid, status, notes, data, synced_at, created_at, updated_at FROM orders_map WHERE uuid = :uuid LIMIT 1');
            $statement->execute(['uuid' => $uuid]);
            $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($result === null) {
                return null;
            }

            $result['data'] = isset($result['data']) ? json_decode((string) $result['data'], true) ?? $result['data'] : null;

            return $result;
        } catch (PDOException) {
            return null;
        }
    }

    public function upsert(string $uuid, array $payload): void
    {
        if ($this->connection === null) {
            return;
        }

        $status = $payload['status'] ?? null;
        $notes = $payload['notes'] ?? null;
        $syncedAt = $payload['synced_at'] ?? null;
        $data = $payload['data'] ?? null;

        try {
            $statement = $this->connection->prepare(
                'INSERT INTO orders_map (uuid, status, notes, data, synced_at, created_at, updated_at)
                 VALUES (:uuid, :status, :notes, :data, :synced_at, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), data = VALUES(data), synced_at = VALUES(synced_at), updated_at = NOW()'
            );

            $statement->execute([
                'uuid' => $uuid,
                'status' => $status,
                'notes' => $notes,
                'data' => $data,
                'synced_at' => $syncedAt,
            ]);
        } catch (PDOException) {
            // Swallow persistence errors to avoid impacting primary flow when database is optional.
        }
    }
}

