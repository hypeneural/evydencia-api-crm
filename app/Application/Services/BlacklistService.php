<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Repositories\BlacklistRepositoryInterface;
use Predis\Client as PredisClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class BlacklistService
{
    private const IDEMPOTENCY_TTL = 86400;

    public function __construct(
        private readonly BlacklistRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly ?PredisClient $redis = null
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, total: int}
     */
    public function list(QueryOptions $options, string $traceId): array
    {
        $criteria = $options->crmQuery;
        $filters = is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [];
        $search = isset($criteria['search']) && is_string($criteria['search']) ? trim($criteria['search']) : null;

        $result = $this->repository->search($filters, $search, $options->page, $options->perPage, $options->fetchAll, $options->sort);

        $items = $result['items'];
        $total = $result['total'];
        $requestedFields = $this->resolveFields($options->fields);

        if ($requestedFields !== []) {
            $items = array_map(fn (array $row): array => $this->projectFields($row, $requestedFields), $items);
        }

        $count = count($items);
        $page = $options->fetchAll ? 1 : $options->page;
        $perPage = $options->fetchAll ? ($count > 0 ? $count : $options->perPage) : $options->perPage;
        $totalPages = $options->fetchAll ? 1 : ($perPage > 0 ? (int) ceil($total / $perPage) : 1);

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'count' => $count,
            'total' => $total,
            'total_pages' => $totalPages,
            'source' => 'api',
        ];

        return [
            'data' => $items,
            'meta' => $meta,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{resource: array<string, mixed>, created: bool}
     */
    public function create(array $payload, string $traceId, ?string $idempotencyKey = null): array
    {
        $name = $this->sanitizeString($payload['name'] ?? '');
        $whatsapp = $this->sanitizeWhatsapp($payload['whatsapp'] ?? '');
        $hasClosedOrder = $this->sanitizeBool($payload['has_closed_order'] ?? false);
        $observation = $this->sanitizeNullableString($payload['observation'] ?? null);

        $errors = [];
        if ($name === '') {
            $errors[] = [
                'field' => 'name',
                'message' => 'Nome obrigatorio.',
            ];
        }

        if ($whatsapp === '') {
            $errors[] = [
                'field' => 'whatsapp',
                'message' => 'Whatsapp obrigatorio.',
            ];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($idempotencyKey !== null && $this->redis !== null) {
            $cached = $this->getIdempotentResource($idempotencyKey, $whatsapp);
            if ($cached !== null) {
                return ['resource' => $cached, 'created' => false];
            }
        }

        $existing = $this->repository->findByWhatsapp($whatsapp);
        if ($existing !== null) {
            if ($idempotencyKey !== null && $this->redis !== null) {
                $this->storeIdempotentResourceId($idempotencyKey, $whatsapp, (int) $existing['id']);
                return ['resource' => $existing, 'created' => false];
            }

            throw new ConflictException('Whatsapp ja cadastrado na blacklist.', [
                ['field' => 'whatsapp', 'message' => 'Whatsapp ja cadastrado.'],
            ]);
        }

        $resource = $this->repository->create([
            'name' => $name,
            'whatsapp' => $whatsapp,
            'has_closed_order' => $hasClosedOrder,
            'observation' => $observation,
        ]);

        if (!isset($resource['id'])) {
            throw new RuntimeException('Falha ao criar registro na blacklist.');
        }

        if ($idempotencyKey !== null && $this->redis !== null) {
            $this->storeIdempotentResourceId($idempotencyKey, $whatsapp, (int) $resource['id']);
        }

        return ['resource' => $resource, 'created' => true];
    }

    public function get(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload, string $traceId): array
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Registro nao encontrado.');
        }

        $updates = [];

        if (array_key_exists('name', $payload)) {
            $updates['name'] = $this->sanitizeString($payload['name']);
        }

        if (array_key_exists('whatsapp', $payload)) {
            $whatsapp = $this->sanitizeWhatsapp($payload['whatsapp']);
            if ($whatsapp === '') {
                throw new ValidationException([
                    ['field' => 'whatsapp', 'message' => 'Whatsapp invalido.'],
                ]);
            }

            $existingWhatsapp = $this->repository->findByWhatsapp($whatsapp);
            if ($existingWhatsapp !== null && (int) $existingWhatsapp['id'] !== $id) {
                throw new ConflictException('Whatsapp ja cadastrado na blacklist.', [
                ['field' => 'whatsapp', 'message' => 'Whatsapp ja cadastrado.'],
            ]);
            }

            $updates['whatsapp'] = $whatsapp;
        }

        if (array_key_exists('has_closed_order', $payload)) {
            $updates['has_closed_order'] = $this->sanitizeBool($payload['has_closed_order']);
        }

        if (array_key_exists('observation', $payload)) {
            $updates['observation'] = $this->sanitizeNullableString($payload['observation']);
        }

        if ($updates === []) {
            return $existing;
        }

        $resource = $this->repository->update($id, $updates);
        if ($resource === null) {
            throw new RuntimeException('Falha ao atualizar registro na blacklist.');
        }

        return $resource;
    }

    public function delete(int $id): bool
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Registro nao encontrado.');
        }

        return $this->repository->delete($id);
    }

    /**
     * @param array<string, array<int, string>> $fields
     * @return array<int, string>
     */
    private function resolveFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        if (isset($fields['blacklist']) && is_array($fields['blacklist'])) {
            return $fields['blacklist'];
        }

        if (isset($fields['default']) && is_array($fields['default'])) {
            return $fields['default'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function projectFields(array $row, array $fields): array
    {
        $projection = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $projection[$field] = $row[$field];
            }
        }

        return $projection;
    }

    private function sanitizeString(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = $this->sanitizeString($value);

        return $string === '' ? null : $string;
    }

    private function sanitizeWhatsapp(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits;
    }

    private function sanitizeBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'sim'], true);
        }

        return false;
    }

    private function getIdempotentResource(string $key, string $whatsapp): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        $cacheKey = $this->idempotencyCacheKey($key, $whatsapp);
        $identifier = $this->redis->get($cacheKey);
        if ($identifier === null) {
            return null;
        }

        $id = (int) $identifier;
        if ($id <= 0) {
            return null;
        }

        return $this->repository->findById($id);
    }

    private function storeIdempotentResourceId(string $key, string $whatsapp, int $id): void
    {
        if ($this->redis === null) {
            return;
        }

        $cacheKey = $this->idempotencyCacheKey($key, $whatsapp);
        $this->redis->setex($cacheKey, self::IDEMPOTENCY_TTL, (string) $id);
    }

    private function idempotencyCacheKey(string $key, string $whatsapp): string
    {
        return sprintf('idempotency:blacklist:%s', sha1($key . ':' . $whatsapp));
    }
}
