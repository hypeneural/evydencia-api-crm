<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Application\Services\Concerns\HandlesListResults;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Infrastructure\Http\EvydenciaApiClient;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ReportService
{
    use HandlesListResults;

    private const MAX_FETCH_PAGES = 20;

    public function __construct(
        private readonly EvydenciaApiClient $apiClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSoldItems(QueryOptions $options, string $traceId): array
    {
        try {
            $apiResponse = $this->apiClient->fetchSoldItems($options->crmQuery, $traceId);
            $body = $apiResponse['body'] ?? [];
            $data = $this->extractData($body);
            $meta = $this->extractMeta($body);
            $links = $this->extractLinks($body);

            if ($options->fetchAll) {
                [$data, $meta, $links] = $this->collectAllPages($options, $traceId, $data, $meta, $links);
            }

            $data = $this->applySort($data, $options->sort);
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
            $this->logger->error('Failed to fetch sold items report', [
                'trace_id' => $traceId,
                'crm_query' => $options->crmQuery,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to fetch sold items report.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchChristmasOrdersWithoutParticipants(string $traceId): array
    {
        return $this->fetchSimpleReport(
            'fetchChristmasOrdersWithoutParticipants',
            $traceId,
            'christmas orders without participants report'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCustomersWithoutChristmasOrders(string $traceId): array
    {
        return $this->fetchSimpleReport(
            'fetchCustomersWithoutChristmasOrders',
            $traceId,
            'customers without christmas orders report'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCustomersWithChristmasOrders(string $traceId): array
    {
        return $this->fetchSimpleReport(
            'fetchCustomersWithChristmasOrders',
            $traceId,
            'customers with christmas orders report'
        );
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

            $apiResponse = $this->apiClient->fetchSoldItems($nextQuery, $traceId);
            $body = $apiResponse['body'] ?? [];
            $nextData = $this->extractData($body);
            $collected = array_merge($collected, $nextData);
            $currentMeta = $this->extractMeta($body);
            $currentLinks = $this->extractLinks($body);

            $pagesFetched++;
        }

        return [$collected, $currentMeta, $currentLinks];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSimpleReport(string $clientMethod, string $traceId, string $operationLabel): array
    {
        if (!method_exists($this->apiClient, $clientMethod)) {
            throw new RuntimeException(sprintf('Method %s is not available on Evydencia API client.', $clientMethod));
        }

        try {
            /** @var array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string} $apiResponse */
            $apiResponse = $this->apiClient->{$clientMethod}($traceId);
            $body = $apiResponse['body'] ?? [];
            $data = $this->extractData($body);

            if ($data === [] && $body !== []) {
                $data = $this->normalizeList($body);
            }

            $meta = $this->extractMeta($body);
            $meta['count'] ??= count($data);
            $meta['page'] ??= 1;
            $meta['per_page'] ??= $meta['size'] ?? (count($data) > 0 ? count($data) : 0);
            if (!isset($meta['total']) && isset($meta['total_items'])) {
                $meta['total'] = $meta['total_items'];
            }
            $meta['total'] ??= count($data);
            $meta['total_items'] ??= $meta['total'];
            $meta['source'] ??= 'crm';

            $links = $this->extractLinks($body);

            return [
                'status' => $apiResponse['status'] ?? 200,
                'data' => $data,
                'meta' => $meta,
                'crm_links' => $links,
            ];
        } catch (CrmUnavailableException|CrmRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error(sprintf('Failed to fetch %s', $operationLabel), [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(sprintf('Unable to fetch %s.', $operationLabel), 0, $exception);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return $payload === null ? [] : [$payload];
        }

        if ($payload === []) {
            return [];
        }

        return $this->isList($payload) ? $payload : [$payload];
    }

    private function isList(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }
}

