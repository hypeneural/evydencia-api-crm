<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Repositories\OrderRepositoryInterface;
use App\Infrastructure\Http\EvydenciaApiClient;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class OrderService
{
    public function __construct(
        private readonly EvydenciaApiClient $apiClient,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function searchOrders(QueryOptions $options, string $traceId): array
    {
        try {
            $response = $this->apiClient->searchOrders($options->crmQuery, $traceId);
            $data = $this->extractData($response);
            $meta = $this->extractMeta($response);
            $links = $this->extractLinks($response);

            if ($options->all) {
                [$data, $meta, $links] = $this->collectAllPages($options, $traceId, $data, $meta, $links);
            }

            $data = $this->mergeLocalMappings($data);
            $data = $this->applySort($data, $options->sort);
            $data = $this->applyProjection($data, $options->fields['orders'] ?? []);

            $finalMeta = $this->buildMeta($meta, $options, count($data));

            return [
                'data' => $data,
                'meta' => $finalMeta,
                'crm_links' => $links,
            ];
        } catch (CrmUnavailableException|CrmRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to search orders via service', [
                'trace_id' => $traceId,
                'crm_query' => $options->crmQuery,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to search orders at this time.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchOrderDetail(string $uuid, string $traceId): array
    {
        try {
            $response = $this->apiClient->fetchOrderDetail($uuid, $traceId);
            $data = $this->extractData($response);

            if (!is_array($data)) {
                $data = $response;
            }

            $local = $this->orderRepository->findByUuid($uuid);
            if ($local !== null) {
                $data['local_map'] = $local;
            }

            return $data;
        } catch (CrmUnavailableException|CrmRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch order detail', [
                'trace_id' => $traceId,
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to fetch order detail at this time.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderStatus(string $uuid, string $status, ?string $note, string $traceId): array
    {
        try {
            $response = $this->apiClient->updateOrderStatus($uuid, $status, $note, $traceId);
            $data = $this->extractData($response);

            $localPayload = [
                'uuid' => $uuid,
                'status' => $status,
                'notes' => $note,
                'synced_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'data' => json_encode([
                    'status' => $status,
                    'note' => $note,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $this->orderRepository->upsert($uuid, $localPayload);

            return is_array($data) ? $data : $response;
        } catch (CrmUnavailableException|CrmRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to update order status', [
                'trace_id' => $traceId,
                'uuid' => $uuid,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to update order status at this time.', 0, $exception);
        }
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function mergeLocalMappings(array $data): array
    {
        foreach ($data as $index => $order) {
            if (!is_array($order)) {
                continue;
            }

            $uuid = (string) ($order['uuid'] ?? $order['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $local = $this->orderRepository->findByUuid($uuid);
            if ($local !== null) {
                $data[$index]['local_map'] = $local;
            }
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function applySort(array $data, array $sortRules): array
    {
        if ($sortRules === [] || $data === []) {
            return $data;
        }

        usort($data, function ($left, $right) use ($sortRules): int {
            foreach ($sortRules as $rule) {
                $field = $rule['field'];
                $direction = $rule['direction'];
                $leftValue = $this->resolveFieldValue($left, $field);
                $rightValue = $this->resolveFieldValue($right, $field);
                $comparison = $this->compareValues($leftValue, $rightValue);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     * @param array<int, string> $fields
     * @return array<int, mixed>
     */
    private function applyProjection(array $data, array $fields): array
    {
        if ($fields === []) {
            return $data;
        }

        $projected = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                $projected[] = $item;
                continue;
            }

            $projection = [];
            foreach ($fields as $path) {
                $value = $this->resolveFieldValue($item, $path);
                if ($value === null) {
                    continue;
                }
                $this->assignByPath($projection, $path, $value);
            }
            $projected[] = $projection;
        }

        return $projected;
    }

    private function resolveFieldValue(mixed $data, string $path): mixed
    {
        if (!is_array($data)) {
            return null;
        }

        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function assignByPath(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $pointer = &$data;
        foreach ($segments as $segment) {
            if (!isset($pointer[$segment]) || !is_array($pointer[$segment])) {
                $pointer[$segment] = [];
            }
            $pointer = &$pointer[$segment];
        }
        $pointer = $value;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return -1;
        }

        if ($right === null) {
            return 1;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        if ($this->isDateTimeString($left) && $this->isDateTimeString($right)) {
            return strtotime((string) $left) <=> strtotime((string) $right);
        }

        return strnatcasecmp((string) $left, (string) $right);
    }

    private function isDateTimeString(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return strtotime($value) !== false;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private function extractData(array $response): array
    {
        $data = $response['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractMeta(array $response): array
    {
        $meta = $response['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractLinks(array $response): array
    {
        $links = $response['links'] ?? null;

        return is_array($links) ? $links : [];
    }

    /**
     * @param array<int, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     * @return array{0: array<int, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function collectAllPages(QueryOptions $options, string $traceId, array $data, array $meta, array $links): array
    {
        $currentLinks = $links;
        $collected = $data;
        $currentMeta = $meta;
        $safetyCounter = 0;

        while (isset($currentLinks['next']) && is_string($currentLinks['next']) && $currentLinks['next'] !== '') {
            $nextQuery = $this->extractQueryFromLink($currentLinks['next']);
            if ($nextQuery === null) {
                break;
            }

            $response = $this->apiClient->searchOrders($nextQuery, $traceId);
            $nextData = $this->extractData($response);
            $collected = array_merge($collected, $nextData);
            $currentMeta = $this->extractMeta($response);
            $currentLinks = $this->extractLinks($response);

            if (++$safetyCounter > 50) {
                break;
            }
        }

        return [$collected, $currentMeta, $currentLinks];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMeta(array $crmMeta, QueryOptions $options, int $count): array
    {
        $totalItems = $crmMeta['total_items'] ?? $crmMeta['total'] ?? $crmMeta['totalItems'] ?? null;
        if (!is_int($totalItems) && !is_float($totalItems)) {
            $totalItems = is_string($totalItems) ? (int) $totalItems : null;
        }

        $totalPages = $crmMeta['total_pages'] ?? $crmMeta['last_page'] ?? null;
        if (!is_int($totalPages) && !is_float($totalPages)) {
            $totalPages = is_string($totalPages) ? (int) $totalPages : null;
        }

        $page = (int) ($crmMeta['page'] ?? $crmMeta['current_page'] ?? $options->page);
        $size = $options->size;

        if ($options->all) {
            $page = 1;
            $size = $count;
            $totalItems = $totalItems ?? $count;
            $totalPages = 1;
        }

        return [
            'page' => $page,
            'size' => $size,
            'count' => $count,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractQueryFromLink(string $link): ?array
    {
        $components = parse_url($link);
        if ($components === false) {
            return null;
        }

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        if ($query === []) {
            return null;
        }

        return $query;
    }
}
