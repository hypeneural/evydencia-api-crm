<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\EventLogResource;
use App\Application\DTO\EventResource;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Repositories\EventRepositoryInterface;

final class EventService
{
    public function __construct(private readonly EventRepositoryInterface $repository)
    {
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $result = $this->repository->list($filters, $page, $perPage);

        $items = array_map(
            static fn (array $row): array => EventResource::fromRepositoryRow($row)->toArray(),
            $result['items']
        );

        $total = $result['total'];
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count($items),
                'total' => $total,
                'total_pages' => $totalPages,
                'filters' => $filters,
                'source' => 'database',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $event = $this->repository->findById($id);
        if ($event === null) {
            throw new NotFoundException('Evento nao encontrado.');
        }

        return EventResource::fromRepositoryRow($event)->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, ?int $usuarioId): array
    {
        $data = $this->validatePayload($payload, true);
        $data['usuario_id'] = $usuarioId;

        return EventResource::fromRepositoryRow($this->repository->create($data))->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, ?int $usuarioId): array
    {
        if ($this->repository->findById($id) === null) {
            throw new NotFoundException('Evento nao encontrado.');
        }

        $data = $this->validatePayload($payload, false);
        $data['usuario_id'] = $usuarioId;

        return EventResource::fromRepositoryRow($this->repository->update($id, $data))->toArray();
    }

    public function delete(int $id, ?int $usuarioId): bool
    {
        return $this->repository->delete($id, $usuarioId);
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function listLogs(int $eventId, int $page, int $perPage): array
    {
        $result = $this->repository->listLogs($eventId, max(1, $page), max(1, min(100, $perPage)));

        return [
            'items' => array_map(
                static fn (array $row): array => EventLogResource::fromRepositoryRow($row)->toArray(),
                $result['items']
            ),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count($result['items']),
                'total' => $result['total'],
                'total_pages' => $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 1,
                'source' => 'database',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $isCreate): array
    {
        $required = ['titulo', 'descricao', 'cidade', 'local', 'inicio', 'fim'];

        $data = [];

        foreach ($required as $field) {
            if ($isCreate) {
                $value = $payload[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    throw new ValidationException([[ 'field' => $field, 'message' => 'Campo obrigatorio.' ]]);
                }
                $data[$field] = $field === 'descricao' ? trim($value) : trim($value);
            } elseif (isset($payload[$field])) {
                $value = $payload[$field];
                if (!is_string($value) || trim($value) === '') {
                    throw new ValidationException([[ 'field' => $field, 'message' => 'Valor invalido.' ]]);
                }
                $data[$field] = trim($value);
            }
        }

        return $data;
    }
}
