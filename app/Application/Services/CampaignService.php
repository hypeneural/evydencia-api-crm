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
            $response = $this->apiClient->fetchCampaignSchedule($options->crmQuery, $traceId);
            $data = $this->extractData($response);
            $meta = $this->extractMeta($response);
            $links = $this->extractLinks($response);

            if ($options->all) {
                [$data, $meta, $links] = $this->collectAllCampaignPages($options, $traceId, $data, $meta, $links);
            }

            $data = $this->applySort($data, $options->sort);
            $finalMeta = $this->buildMeta($meta, $options, count($data));

            return [
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
    private function collectAllCampaignPages(QueryOptions $options, string $traceId, array $data, array $meta, array $links): array
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

            $response = $this->apiClient->fetchCampaignSchedule($nextQuery, $traceId);
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
}
