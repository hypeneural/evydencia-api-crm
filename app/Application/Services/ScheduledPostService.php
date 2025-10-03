<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Repositories\ScheduledPostRepositoryInterface;
use App\Infrastructure\Cache\ScheduledPostCache;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ScheduledPostService
{
    private const ALLOWED_TYPES = ['text', 'image', 'video'];
    private const READY_DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly ScheduledPostRepositoryInterface $repository,
        private readonly ScheduledPostCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, total: int, max_updated_at: string|null}
     */
    public function list(QueryOptions $options, string $traceId): array
    {
        $criteria = $options->crmQuery;
        $filters = is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [];
        $search = isset($criteria['search']) && is_string($criteria['search']) ? trim($criteria['search']) : null;

        $result = $this->repository->search($filters, $search, $options->page, $options->perPage, $options->fetchAll, $options->sort);

        $items = $result['items'];
        $total = $result['total'];
        $maxUpdatedAt = $result['max_updated_at'] ?? null;

        $fields = $this->resolveFields($options->fields);
        if ($fields !== []) {
            $items = array_map(fn (array $row): array => $this->projectFields($row, $fields), $items);
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
            'max_updated_at' => $maxUpdatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, string $traceId): array
    {
        $normalized = $this->sanitizePayload($payload, true);
        $errors = $this->validatePayload($normalized, true);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $resource = $this->repository->create($normalized);
        if (!isset($resource['id'])) {
            throw new RuntimeException('Falha ao criar agendamento.');
        }

        $this->cache->invalidate();

        return $resource;
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
            throw new NotFoundException('Agendamento nao encontrado.');
        }

        $normalized = $this->sanitizePayload($payload, false);
        $errors = $this->validatePayload($normalized, false, $existing);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $resource = $this->repository->update($id, $normalized);
        if ($resource === null) {
            throw new RuntimeException('Falha ao atualizar agendamento.');
        }

        $this->cache->invalidate();

        return $resource;
    }

    public function delete(int $id): bool
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Agendamento nao encontrado.');
        }

        $deleted = $this->repository->delete($id);
        if ($deleted) {
            $this->cache->invalidate();
        }

        return $deleted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReady(?int $limit = null): array
    {
        $limit = $limit !== null && $limit > 0 ? $limit : self::READY_DEFAULT_LIMIT;

        return $this->repository->findReady($limit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function markSent(int $id, array $payload): array
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Agendamento nao encontrado.');
        }

        $zaapId = $this->sanitizeOptionalString($payload['zaapId'] ?? null);
        $messageId = $this->sanitizeOptionalString($payload['messageId'] ?? null);

        if ($messageId === null || $messageId === '') {
            throw new ValidationException([
                ['field' => 'messageId', 'message' => 'messageId obrigatorio.'],
            ]);
        }

        $updated = $this->repository->markSent($id, [
            'zaapId' => $zaapId,
            'messageId' => $messageId,
        ]);

        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar agendamento como enviado.');
        }

        $this->cache->invalidate();

        return $updated;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, string>
     */
    public function resolveFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        if (isset($fields['scheduled_posts']) && is_array($fields['scheduled_posts'])) {
            return $fields['scheduled_posts'];
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload, bool $isCreate): array
    {
        if (array_key_exists('type', $payload)) {
            $payload['type'] = strtolower(trim((string) $payload['type']));
        }

        foreach (['message', 'image_url', 'video_url', 'caption', 'zaapId', 'messageId'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->sanitizeOptionalString($payload[$field]);
            }
        }

        if (array_key_exists('scheduled_datetime', $payload)) {
            $payload['scheduled_datetime'] = $this->sanitizeDateTime($payload['scheduled_datetime']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private function validatePayload(array $payload, bool $isCreate, array $current = []): array
    {
        $errors = [];
        $final = array_merge($current, $payload);
        $type = $final['type'] ?? null;

        if ($isCreate && !is_string($type)) {
            $errors[] = ['field' => 'type', 'message' => 'Tipo obrigatorio.'];
        }

        if (is_string($type) && !in_array($type, self::ALLOWED_TYPES, true)) {
            $errors[] = ['field' => 'type', 'message' => 'Tipo invalido.'];
        }

        $scheduled = $payload['scheduled_datetime'] ?? null;
        if ($scheduled === null && isset($current['scheduled_datetime'])) {
            $scheduled = $current['scheduled_datetime'];
        }
        if ($isCreate && ($scheduled === null || $scheduled === '')) {
            $errors[] = ['field' => 'scheduled_datetime', 'message' => 'scheduled_datetime obrigatorio.'];
        }

        if ($scheduled !== null && $scheduled !== '') {
            if (!$this->isValidDateTime($scheduled)) {
                $errors[] = ['field' => 'scheduled_datetime', 'message' => 'Data de agendamento invalida.'];
            }
        }

        if ($type === 'text') {
            $message = $payload['message'] ?? null;
            if ($isCreate && ($message === null || $message === '')) {
                $errors[] = ['field' => 'message', 'message' => 'Mensagem obrigatoria para posts de texto.'];
            }
        }

        if ($type === 'image') {
            $imageUrl = $payload['image_url'] ?? null;
            if ($isCreate && ($imageUrl === null || $imageUrl === '')) {
                $errors[] = ['field' => 'image_url', 'message' => 'image_url obrigatoria para posts de imagem.'];
            }
        }

        if ($type === 'video') {
            $videoUrl = $payload['video_url'] ?? null;
            if ($isCreate && ($videoUrl === null || $videoUrl === '')) {
                $errors[] = ['field' => 'video_url', 'message' => 'video_url obrigatoria para posts de video.'];
            }
        }

        return $errors;
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sanitizeDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        try {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $dateTime = new DateTimeImmutable($stringValue, $timezone);

            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function isValidDateTime(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            new DateTimeImmutable($value, $timezone);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
