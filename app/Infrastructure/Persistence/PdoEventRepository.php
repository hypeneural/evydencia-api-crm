<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\EventRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoEventRepository implements EventRepositoryInterface
{
    public function __construct(private readonly ?PDO $connection)
    {
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $pdo = $this->requireConnection();

        $conditions = [];
        $params = [];

        if (isset($filters['cidade']) && $filters['cidade'] !== '') {
            $conditions[] = 'LOWER(e.cidade) = LOWER(:cidade)';
            $params['cidade'] = $filters['cidade'];
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
            $conditions[] = '(e.titulo LIKE :search OR e.descricao LIKE :search OR e.local LIKE :search)';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $orderBy = ' ORDER BY e.inicio DESC';

        $offset = max(0, ($page - 1) * $perPage);

        $sql = '
            SELECT
                e.id,
                e.titulo,
                e.descricao,
                e.cidade,
                e.local,
                e.inicio,
                e.fim,
                e.criado_por,
                e.atualizado_por,
                e.criado_em,
                e.atualizado_em
            FROM eventos e
        ' . $where . $orderBy . ' LIMIT :limit OFFSET :offset';

        $statement = $pdo->prepare($sql);
        $this->bindParameters($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM eventos e' . $where);
        $this->bindParameters($countStmt, $params);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        return [
            'items' => array_map([$this, 'normalizeRow'], $items),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?array
    {
        $pdo = $this->requireConnection();
        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.titulo,
                e.descricao,
                e.cidade,
                e.local,
                e.inicio,
                e.fim,
                e.criado_por,
                e.atualizado_por,
                e.criado_em,
                e.atualizado_em
            FROM eventos e
            WHERE e.id = :id
            LIMIT 1'
        );

        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    public function create(array $data): array
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO eventos (titulo, descricao, cidade, local, inicio, fim, criado_por, atualizado_por)
             VALUES (:titulo, :descricao, :cidade, :local, :inicio, :fim, :criado_por, :atualizado_por)'
        );

        $statement->execute([
            ':titulo' => $data['titulo'],
            ':descricao' => $data['descricao'],
            ':cidade' => $data['cidade'],
            ':local' => $data['local'],
            ':inicio' => $data['inicio'],
            ':fim' => $data['fim'],
            ':criado_por' => $data['usuario_id'],
            ':atualizado_por' => $data['usuario_id'],
        ]);

        $id = (int) $pdo->lastInsertId();

        $created = $this->findById($id);
        if ($created === null) {
            throw new RuntimeException('Nao foi possivel carregar o evento criado.');
        }

        $this->log($id, $data['usuario_id'], 'create', null, $created);

        return $created;
    }

    public function update(int $id, array $data): array
    {
        $pdo = $this->requireConnection();

        $current = $this->findById($id);
        if ($current === null) {
            throw new RuntimeException('Evento nao encontrado.');
        }

        $columns = [
            'titulo' => $data['titulo'] ?? $current['titulo'],
            'descricao' => $data['descricao'] ?? $current['descricao'],
            'cidade' => $data['cidade'] ?? $current['cidade'],
            'local' => $data['local'] ?? $current['local'],
            'inicio' => $data['inicio'] ?? $current['inicio'],
            'fim' => $data['fim'] ?? $current['fim'],
            'atualizado_por' => $data['usuario_id'] ?? null,
        ];

        $statement = $pdo->prepare(
            'UPDATE eventos
             SET titulo = :titulo,
                 descricao = :descricao,
                 cidade = :cidade,
                 local = :local,
                 inicio = :inicio,
                 fim = :fim,
                 atualizado_por = :atualizado_por,
                 atualizado_em = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute([
            ':titulo' => $columns['titulo'],
            ':descricao' => $columns['descricao'],
            ':cidade' => $columns['cidade'],
            ':local' => $columns['local'],
            ':inicio' => $columns['inicio'],
            ':fim' => $columns['fim'],
            ':atualizado_por' => $columns['atualizado_por'],
            ':id' => $id,
        ]);

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException('Nao foi possivel atualizar o evento.');
        }

        $this->log($id, $columns['atualizado_por'], 'update', $current, $updated);

        return $updated;
    }

    public function delete(int $id, ?int $usuarioId): bool
    {
        $pdo = $this->requireConnection();
        $current = $this->findById($id);
        if ($current === null) {
            return false;
        }

        $statement = $pdo->prepare('DELETE FROM eventos WHERE id = :id');
        $statement->execute([':id' => $id]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted) {
            $this->log($id, $usuarioId, 'delete', $current, null);
        }

        return $deleted;
    }

    public function listLogs(int $eventId, int $page, int $perPage): array
    {
        $pdo = $this->requireConnection();

        $offset = max(0, ($page - 1) * $perPage);

        $statement = $pdo->prepare(
            'SELECT
                l.id,
                l.evento_id,
                l.usuario_id,
                l.acao,
                l.payload_antigo,
                l.payload_novo,
                l.criado_em
             FROM evento_logs l
             WHERE l.evento_id = :id
             ORDER BY l.criado_em DESC
             LIMIT :limit OFFSET :offset'
        );

        $statement->bindValue(':id', $eventId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM evento_logs WHERE evento_id = :id');
        $countStmt->execute([':id' => $eventId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $items = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'acao' => $row['acao'],
                'usuario_id' => $row['usuario_id'] !== null ? (int) $row['usuario_id'] : null,
                'payload_antigo' => $this->decodeJson($row['payload_antigo']),
                'payload_novo' => $this->decodeJson($row['payload_novo']),
                'criado_em' => $this->formatDateTime($row['criado_em']),
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    private function log(int $eventoId, ?int $usuarioId, string $acao, ?array $before, ?array $after): void
    {
        $pdo = $this->requireConnection();

        $statement = $pdo->prepare(
            'INSERT INTO evento_logs (evento_id, usuario_id, acao, payload_antigo, payload_novo)
             VALUES (:evento_id, :usuario_id, :acao, :payload_antigo, :payload_novo)'
        );

        $statement->execute([
            ':evento_id' => $eventoId,
            ':usuario_id' => $usuarioId,
            ':acao' => $acao,
            ':payload_antigo' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':payload_novo' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'titulo' => $row['titulo'],
            'descricao' => $row['descricao'],
            'cidade' => $row['cidade'],
            'local' => $row['local'],
            'inicio' => $this->formatDateTime($row['inicio']),
            'fim' => $this->formatDateTime($row['fim']),
            'criado_por' => $row['criado_por'] !== null ? (int) $row['criado_por'] : null,
            'atualizado_por' => $row['atualizado_por'] !== null ? (int) $row['atualizado_por'] : null,
            'criado_em' => $this->formatDateTime($row['criado_em']),
            'atualizado_em' => $this->formatDateTime($row['atualizado_em']),
        ];
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

    private function requireConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection is not configured.');
        }

        return $this->connection;
    }
}
