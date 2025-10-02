<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Application\Services\Concerns\HandlesListResults;
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
    use HandlesListResults;

    private const MAX_FETCH_PAGES = 20;

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
            $apiResponse = $this->apiClient->searchOrders($options->crmQuery, $traceId);
            $body = $apiResponse['body'] ?? [];
            $data = $this->extractData($body);
            $meta = $this->extractMeta($body);
            $links = $this->extractLinks($body);

            if ($options->fetchAll) {
                [$data, $meta, $links] = $this->collectAllPages($options, $traceId, $data, $meta, $links);
            }

            $data = $this->mergeLocalMappings($data);
            $data = $this->applySort($data, $options->sort);
            $data = $this->applyProjection($data, $options->fields['orders'] ?? []);

            $finalMeta = $this->buildMeta($meta, $options, count($data));
            $finalMeta['per_page'] = $options->perPage;
            $finalMeta['source'] = 'crm';

            return [
                'status' => $apiResponse['status'] ?? 200,
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
            $apiResponse = $this->apiClient->fetchOrderDetail($uuid, $traceId);
            $body = $apiResponse['body'] ?? [];
            $data = $this->extractData($body);

            if (!is_array($data) || $data === []) {
                $data = $body;
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
            $apiResponse = $this->apiClient->updateOrderStatus($uuid, $status, $note, $traceId);
            $body = $apiResponse['body'] ?? [];
            $data = $this->extractData($body);

            if (!is_array($data) || $data === []) {
                $data = $body;
            }

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

            return $data;
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
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     * @return array{0: array<int, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function collectAllPages(QueryOptions $options, string $traceId, array $data, array $meta, array $links): array
    {
        $currentLinks = $links;
        $collected = $data;
        $currentMeta = $meta;
        $pagesFetched = 1;

        while (isset($currentLinks['next']) && is_string($currentLinks['next']) && $currentLinks['next'] !== '') {
            if ($pagesFetched >= self::MAX_FETCH_PAGES) {
                break;
            }

            $nextQuery = $this->extractQueryFromLink($currentLinks['next']);
            if ($nextQuery === null) {
                break;
            }

            if (!isset($nextQuery['page'])) {
                break;
            }

            $nextQuery['per_page'] = isset($nextQuery['per_page'])
                ? (int) $nextQuery['per_page']
                : $options->perPage;

            $apiResponse = $this->apiClient->searchOrders($nextQuery, $traceId);
            $body = $apiResponse['body'] ?? [];
            $nextData = $this->extractData($body);
            $collected = array_merge($collected, $nextData);
            $currentMeta = $this->extractMeta($body);
            $currentLinks = $this->extractLinks($body);

            $pagesFetched++;
        }

        return [$collected, $currentMeta, $currentLinks];
    }
}

