<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Exception\ZapiRequestException;
use App\Domain\Repositories\ScheduledPostRepositoryInterface;
use App\Infrastructure\Cache\ScheduledPostCache;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ScheduledPostService
{
    private const ALLOWED_TYPES = ['text', 'image', 'video'];
    private const READY_DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly ScheduledPostRepositoryInterface $repository,
        private readonly ScheduledPostCache $cache,
        private readonly WhatsAppService $whatsAppService,
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

        if ($this->shouldDispatchImmediately($resource['scheduled_datetime'] ?? null)) {
            $dispatchResult = $this->dispatchSingle($resource, $traceId);
            if (($dispatchResult['status'] ?? null) === 'sent' && isset($dispatchResult['resource']) && is_array($dispatchResult['resource'])) {
                $resource = $dispatchResult['resource'];
            }
        }

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
     * @return array<string, mixed>
     */
    public function dispatchReady(?int $limit, string $traceId): array
    {
        $limit = $limit !== null && $limit > 0 ? $limit : self::READY_DEFAULT_LIMIT;
        $pending = $this->repository->findReady($limit);

        $results = [];
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($pending as $post) {
            $dispatch = $this->dispatchSingle($post, $traceId);
            $status = $dispatch['status'] ?? 'failed';

            if ($status === 'sent') {
                $sent++;
            } elseif ($status === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }

            $results[] = $this->prepareDispatchOutput($dispatch);
        }

        return [
            'summary' => [
                'limit' => $limit,
                'processed' => count($pending),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
            ],
            'items' => $results,
        ];
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
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function dispatchSingle(array $post, string $traceId): array
    {
        $id = isset($post['id']) ? (int) $post['id'] : 0;
        $type = is_string($post['type'] ?? null) ? strtolower((string) $post['type']) : '';

        $base = [
            'id' => $id,
            'type' => $type,
            'scheduled_datetime' => $post['scheduled_datetime'] ?? null,
        ];

        if ($id <= 0 || $type === '') {
            return array_merge($base, [
                'status' => 'skipped',
                'error' => 'Agendamento com dados incompletos.',
            ]);
        }

        try {
            $result = match ($type) {
                'text' => $this->dispatchTextStatus($post, $traceId),
                'image' => $this->dispatchImageStatus($post, $traceId),
                'video' => $this->dispatchVideoStatus($post, $traceId),
                default => null,
            };
        } catch (ZapiRequestException $exception) {
            $this->logger->warning('Failed to dispatch scheduled post via Z-API', [
                'trace_id' => $traceId,
                'post_id' => $id,
                'type' => $type,
                'provider_status' => $exception->getStatus(),
            ]);

            return array_merge($base, array_filter([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'provider_status' => $exception->getStatus(),
                'provider_body' => $this->whatsAppService->isDebug() ? $exception->getBody() : null,
            ], static fn ($value) => $value !== null));
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error while dispatching scheduled post', [
                'trace_id' => $traceId,
                'post_id' => $id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return array_merge($base, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
        }

        if ($result === null) {
            $this->logger->warning('Scheduled post skipped due to unsupported type', [
                'trace_id' => $traceId,
                'post_id' => $id,
                'type' => $type,
            ]);

            return array_merge($base, [
                'status' => 'skipped',
                'error' => sprintf('Tipo nao suportado: %s', $type),
            ]);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];

        $messageId = $this->sanitizeOptionalString($data['messageId'] ?? null);
        if ($messageId === null) {
            $this->logger->warning('Z-API response missing messageId for scheduled post', [
                'trace_id' => $traceId,
                'post_id' => $id,
                'type' => $type,
            ]);

            return array_merge($base, array_filter([
                'status' => 'failed',
                'error' => 'Resposta da Z-API sem messageId.',
                'provider_status' => $meta['provider_status'] ?? null,
                'provider_body' => $this->whatsAppService->isDebug() ? ($meta['provider_body'] ?? null) : null,
            ], static fn ($value) => $value !== null));
        }

        $zaapId = $this->sanitizeOptionalString($data['zaapId'] ?? null);

        try {
            $updated = $this->finalizeSent($id, $zaapId, $messageId);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to mark scheduled post as sent after dispatch', [
                'trace_id' => $traceId,
                'post_id' => $id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return array_merge($base, array_filter([
                'status' => 'failed',
                'error' => 'Falha ao atualizar o agendamento como enviado.',
                'provider_status' => $meta['provider_status'] ?? null,
            ], static fn ($value) => $value !== null));
        }

        return array_merge($base, array_filter([
            'status' => 'sent',
            'messageId' => $updated['messageId'] ?? $messageId,
            'zaapId' => $updated['zaapId'] ?? $zaapId,
            'provider_status' => $meta['provider_status'] ?? null,
            'resource' => $updated,
        ], static fn ($value) => $value !== null));
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function dispatchTextStatus(array $post, string $traceId): array
    {
        $message = $this->sanitizeOptionalString($post['message'] ?? null);
        if ($message === null) {
            throw new RuntimeException('Mensagem obrigatoria ausente para post de texto.');
        }

        return $this->whatsAppService->sendTextStatus($traceId, $message);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function dispatchImageStatus(array $post, string $traceId): array
    {
        $image = $this->sanitizeOptionalString($post['image_url'] ?? null);
        if ($image === null) {
            throw new RuntimeException('URL da imagem obrigatoria para post de imagem.');
        }

        $caption = $this->sanitizeOptionalString($post['caption'] ?? null);

        return $this->whatsAppService->sendImageStatus($traceId, $image, $caption);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function dispatchVideoStatus(array $post, string $traceId): array
    {
        $video = $this->sanitizeOptionalString($post['video_url'] ?? null);
        if ($video === null) {
            throw new RuntimeException('URL do video obrigatoria para post de video.');
        }

        $caption = $this->sanitizeOptionalString($post['caption'] ?? null);

        return $this->whatsAppService->sendVideoStatus($traceId, $video, $caption);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function prepareDispatchOutput(array $result): array
    {
        $output = [
            'id' => $result['id'] ?? null,
            'type' => $result['type'] ?? null,
            'scheduled_datetime' => $result['scheduled_datetime'] ?? null,
            'status' => $result['status'] ?? null,
        ];

        foreach (['messageId', 'zaapId', 'provider_status', 'error'] as $field) {
            if (array_key_exists($field, $result) && $result[$field] !== null) {
                $output[$field] = $result[$field];
            }
        }

        if ($this->whatsAppService->isDebug() && isset($result['provider_body'])) {
            $output['provider_body'] = $result['provider_body'];
        }

        return array_filter(
            $output,
            static fn ($value) => $value !== null && $value !== ''
        );
    }

    private function finalizeSent(int $id, ?string $zaapId, string $messageId): array
    {
        $payload = [
            'zaapId' => $zaapId,
            'messageId' => $messageId,
        ];

        $updated = $this->repository->markSent($id, $payload);
        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar agendamento como enviado.');
        }

        $this->cache->invalidate();

        return $updated;
    }

    private function shouldDispatchImmediately(mixed $scheduled): bool
    {
        if (!is_string($scheduled) || trim($scheduled) === '') {
            return false;
        }

        try {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $scheduledDate = new DateTimeImmutable($scheduled, $timezone);
            $now = new DateTimeImmutable('now', $timezone);

            return $scheduledDate <= $now;
        } catch (\Exception) {
            return false;
        }
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
