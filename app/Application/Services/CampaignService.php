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

final class CampaignService
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
    public function fetchSchedule(QueryOptions $options, string $traceId): array
    {
        try {
            $apiResponse = $this->apiClient->fetchCampaignSchedule($options->crmQuery, $traceId);
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
            $this->logger->error('Failed to fetch campaign schedule', [
                'trace_id' => $traceId,
                'crm_query' => $options->crmQuery,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to fetch campaign schedule.', 0, $exception);
        }
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

            $apiResponse = $this->apiClient->fetchCampaignSchedule($nextQuery, $traceId);
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

