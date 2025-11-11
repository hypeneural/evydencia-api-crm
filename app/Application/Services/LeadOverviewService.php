<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\LeadOverviewOptions;
use App\Application\Services\Concerns\HandlesListResults;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Settings\Settings;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class LeadOverviewService
{
    use HandlesListResults;

    private const MAX_PAGINATION_PAGES = 20;

    /**
     * @var array<string, string>
     */
    private const LEAD_STATUS_LABELS = [
        'recent' => 'Novo',
        'converted' => 'Convertido',
        'losted' => 'Perdido',
    ];

    /**
     * @var array<string, string>
     */
    private const CAMPAIGN_STATUS_LABELS = [
        'scheduled' => 'Agendado',
        'completed' => 'Concluído',
    ];

    private DateTimeZone $appTimezone;
    private DateTimeZone $utcTimezone;

    public function __construct(
        private readonly EvydenciaApiClient $apiClient,
        Settings $settings,
        private readonly LoggerInterface $logger
    ) {
        $app = $settings->getApp();
        $timezone = is_string($app['timezone'] ?? null) ? $app['timezone'] : 'UTC';

        try {
            $this->appTimezone = new DateTimeZone($timezone);
        } catch (Exception) {
            $this->appTimezone = new DateTimeZone('UTC');
        }
        $this->utcTimezone = new DateTimeZone('UTC');
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function getOverview(LeadOverviewOptions $options, string $traceId): array
    {
        try {
            $schedules = $this->collectSchedules($options, $traceId);

            return $this->buildOverview($schedules, $options);
        } catch (CrmUnavailableException|CrmRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to build lead overview', [
                'trace_id' => $traceId,
                'campaign_ids' => $options->campaignIds,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to build lead overview.', 0, $exception);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecentLeads(LeadOverviewOptions $options, string $traceId): array
    {
        $overview = $this->getOverview($options, $traceId);
        $recentLeads = $overview['data']['recent_leads'] ?? [];

        return is_array($recentLeads) ? $recentLeads : [];
    }

    /**
     * @param array<int, mixed> $schedules
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function buildOverview(array $schedules, LeadOverviewOptions $options): array
    {
        $leadRecords = [];
        $campaignScheduled = 0;
        $campaignCompleted = 0;
        $lastUpdate = null;

        foreach ($schedules as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!$this->usesLeadSystem($item)) {
                continue;
            }

            $itemStatusName = $this->normalizeStatusName($item['status']['name'] ?? null);
            if ($itemStatusName === 'scheduled') {
                $campaignScheduled++;
            } elseif ($itemStatusName === 'completed') {
                $campaignCompleted++;
            }

            $itemUpdatedAt = $this->parseDateTime($item['updated_at'] ?? $item['created_at'] ?? null);
            if ($itemUpdatedAt !== null && ($lastUpdate === null || $itemUpdatedAt > $lastUpdate)) {
                $lastUpdate = $itemUpdatedAt;
            }

            $contacts = $item['contacts'] ?? [];
            if (!is_array($contacts)) {
                continue;
            }

            foreach ($contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $record = $this->buildLeadRecord($item, $contact, $options);
                if ($record === null) {
                    continue;
                }

                $key = $record['dedupe_key'];
                $existing = $leadRecords[$key] ?? null;

                if ($existing === null || $this->shouldReplaceRecord($record, $existing)) {
                    $leadRecords[$key] = $record;
                }
            }
        }

        if ($leadRecords !== []) {
            foreach ($leadRecords as $record) {
                $updatedAt = $record['updated_at_dt'];
                if ($updatedAt !== null && ($lastUpdate === null || $updatedAt > $lastUpdate)) {
                    $lastUpdate = $updatedAt;
                }
            }
        }

        $uniqueRecords = array_values($leadRecords);
        usort($uniqueRecords, function (array $left, array $right): int {
            $leftInstant = $left['sort_instant'];
            $rightInstant = $right['sort_instant'];

            if ($leftInstant !== null && $rightInstant !== null) {
                $comparison = $rightInstant <=> $leftInstant;
                if ($comparison !== 0) {
                    return $comparison;
                }
            } elseif ($leftInstant !== null) {
                return -1;
            } elseif ($rightInstant !== null) {
                return 1;
            }

            $leftUpdated = $left['updated_at_dt'];
            $rightUpdated = $right['updated_at_dt'];

            if ($leftUpdated !== null && $rightUpdated !== null) {
                $comparison = $rightUpdated <=> $leftUpdated;
                if ($comparison !== 0) {
                    return $comparison;
                }
            } elseif ($leftUpdated !== null) {
                return -1;
            } elseif ($rightUpdated !== null) {
                return 1;
            }

            return (($right['lead']['lead_id'] ?? 0) <=> ($left['lead']['lead_id'] ?? 0));
        });

        $recentLeads = array_map(
            static fn (array $record): array => $record['lead'],
            array_slice($uniqueRecords, 0, $options->limit)
        );

        $totalLeads = count($uniqueRecords);
        $statusCounters = [
            'recent' => 0,
            'converted' => 0,
            'losted' => 0,
        ];

        $statusDistribution = [];
        $dddDistribution = [];
        $periodTotals = [
            'today' => 0,
            'yesterday' => 0,
            'last_7_days' => 0,
        ];

        $now = new DateTimeImmutable('now', $this->appTimezone);
        $startOfToday = $now->setTime(0, 0);
        $startOfYesterday = $startOfToday->sub(new DateInterval('P1D'));
        $startOfLastSevenDays = $startOfToday->sub(new DateInterval('P6D'));

        foreach ($uniqueRecords as $record) {
            $statusName = $record['status_name'];
            if (isset($statusCounters[$statusName])) {
                $statusCounters[$statusName]++;
            }

            $statusDistribution[$statusName] = ($statusDistribution[$statusName] ?? ['count' => 0, 'label_pt' => $this->translateLeadStatus($statusName)]);
            $statusDistribution[$statusName]['count']++;

            $createdAt = $record['created_at_dt'];
            if ($createdAt !== null && $statusName === 'recent') {
                $createdAtAppTz = $createdAt->setTimezone($this->appTimezone);
                if ($createdAtAppTz >= $startOfToday) {
                    $periodTotals['today']++;
                } elseif ($createdAtAppTz >= $startOfYesterday && $createdAtAppTz < $startOfToday) {
                    $periodTotals['yesterday']++;
                }
                if ($createdAtAppTz >= $startOfLastSevenDays) {
                    $periodTotals['last_7_days']++;
                }
            }

            $ddd = $record['whatsapp_ddd'];
            if ($ddd !== null) {
                if (!isset($dddDistribution[$ddd])) {
                    $dddDistribution[$ddd] = [
                        'count' => 0,
                        'percentage' => 0.0,
                    ];
                }
                $dddDistribution[$ddd]['count']++;
            }
        }

        foreach (array_keys(self::LEAD_STATUS_LABELS) as $statusKey) {
            if (!isset($statusDistribution[$statusKey])) {
                $statusDistribution[$statusKey] = [
                    'count' => 0,
                    'label_pt' => $this->translateLeadStatus($statusKey),
                ];
            }
        }

        ksort($statusDistribution);
        ksort($dddDistribution);

        $statusSummary = [
            'total_leads' => $totalLeads,
            'novos' => $statusCounters['recent'],
            'convertidos' => $statusCounters['converted'],
            'perdidos' => $statusCounters['losted'],
        ];

        foreach ($statusDistribution as $status => &$distribution) {
            $count = $distribution['count'];
            $percentage = $totalLeads > 0 ? round(($count / $totalLeads) * 100, 2) : 0.0;
            $distribution = [
                'count' => $count,
                'percentage' => $percentage,
                'label_pt' => $this->translateLeadStatus($status),
            ];
        }
        unset($distribution);

        $totalLeadsForDdd = $totalLeads > 0 ? $totalLeads : 1;
        foreach ($dddDistribution as $ddd => &$distribution) {
            $count = $distribution['count'];
            $distribution['percentage'] = round(($count / $totalLeadsForDdd) * 100, 2);
        }
        unset($distribution);

        $campaignStatus = [
            'scheduled' => $campaignScheduled,
            'completed' => $campaignCompleted,
            'all_messages_sent' => $campaignScheduled === 0,
            'last_update' => $this->formatDate($lastUpdate),
        ];

        $statusOrder = array_keys(self::LEAD_STATUS_LABELS);
        $orderMap = array_flip($statusOrder);
        uksort($statusDistribution, static function (string $left, string $right) use ($orderMap): int {
            $leftOrder = $orderMap[$left] ?? PHP_INT_MAX;
            $rightOrder = $orderMap[$right] ?? PHP_INT_MAX;

            if ($leftOrder === $rightOrder) {
                return strcmp($left, $right);
            }

            return $leftOrder <=> $rightOrder;
        });

        return [
            'data' => [
                'summary' => $statusSummary,
                'campaign_status' => $campaignStatus,
                'recent_leads' => $recentLeads,
            ],
            'meta' => [
                'count' => $totalLeads,
                'source' => 'crm',
                'extra' => [
                    'campaign_ids' => $options->campaignIds,
                    'dedupe_by' => $options->dedupeBy,
                    'period_totals' => $periodTotals,
                    'status_distribution' => $statusDistribution,
                    'whatsapp_ddd_distribution' => $dddDistribution,
                ],
            ],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function collectSchedules(LeadOverviewOptions $options, string $traceId): array
    {
        $collected = [];

        foreach ($options->campaignIds as $campaignId) {
            $collected = array_merge($collected, $this->fetchSchedulesForCampaign($campaignId, $traceId));
        }

        return $collected;
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchSchedulesForCampaign(int $campaignId, string $traceId): array
    {
        $query = [
            'campaign[id]' => $campaignId,
        ];

        $collected = [];
        $nextQuery = $query;
        $pagesFetched = 0;

        do {
            $apiResponse = $this->apiClient->fetchCampaignSchedule($nextQuery, $traceId);
            $body = $apiResponse['body'] ?? [];
            $items = $this->extractItems($body);
            $collected = array_merge($collected, $items);

            $links = $this->extractLinksFromBody($body);
            $nextQuery = $this->resolveNextQuery($links);
            $pagesFetched++;
        } while ($nextQuery !== null && $pagesFetched < self::MAX_PAGINATION_PAGES);

        return $collected;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractItems(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $data = $this->extractData($body);
        if ($data !== []) {
            return array_values(array_filter($data, static fn ($item): bool => is_array($item)));
        }

        if (array_is_list($body)) {
            return array_values(array_filter($body, static fn ($item): bool => is_array($item)));
        }

        if (isset($body['items']) && is_array($body['items'])) {
            return array_values(array_filter($body['items'], static fn ($item): bool => is_array($item)));
        }

        return [];
    }

    /**
     * @param array<int, mixed>|array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractLinksFromBody(array $body): array
    {
        if ($body === []) {
            return [];
        }

        if (isset($body['links']) && is_array($body['links'])) {
            return $body['links'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $links
     * @return array<string, mixed>|null
     */
    private function resolveNextQuery(array $links): ?array
    {
        if (!isset($links['next']) || !is_string($links['next']) || $links['next'] === '') {
            return null;
        }

        return $this->extractQueryFromLink($links['next']);
    }

    private function usesLeadSystem(array $item): bool
    {
        $value = $item['use_lead_system'] ?? $item['use_leads_system'] ?? $item['useLeadSystem'] ?? null;

        return (int) $value === 1;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $contact
     */
    private function buildLeadRecord(array $item, array $contact, LeadOverviewOptions $options): ?array
    {
        $lead = $contact['lead'] ?? null;
        if (!is_array($lead)) {
            return null;
        }

        $leadId = $this->normalizeInt($lead['id'] ?? null);
        $whatsapp = $this->extractWhatsapp($lead, $contact);
        $dedupeKey = $options->dedupeBy === LeadOverviewOptions::DEDUPE_BY_WHATSAPP
            ? ($whatsapp !== '' ? $whatsapp : null)
            : ($leadId !== null ? (string) $leadId : null);

        if ($dedupeKey === null) {
            return null;
        }

        $leadCreatedAt = $this->parseDateTime($lead['created_at'] ?? null);
        $contactCreatedAt = $this->parseDateTime($contact['created_at'] ?? null);
        $itemCreatedAt = $this->parseDateTime($item['created_at'] ?? null);

        $primaryCreatedAt = $leadCreatedAt ?? $contactCreatedAt ?? $itemCreatedAt;

        if ($options->dateFrom !== null) {
            if ($primaryCreatedAt === null || $primaryCreatedAt < $options->dateFrom) {
                return null;
            }
        }

        if ($options->dateTo !== null) {
            if ($primaryCreatedAt === null || $primaryCreatedAt > $options->dateTo) {
                return null;
            }
        }

        $leadUpdatedAt = $this->parseDateTime($lead['updated_at'] ?? null);
        $contactUpdatedAt = $this->parseDateTime($contact['updated_at'] ?? null);
        $itemUpdatedAt = $this->parseDateTime($item['updated_at'] ?? null);

        $status = is_array($lead['status'] ?? null) ? $lead['status'] : [];
        $statusName = $this->normalizeStatusName($status['name'] ?? null);

        $campaignStatus = is_array($item['status'] ?? null) ? $item['status'] : [];
        $campaignStatusName = $this->normalizeStatusName($campaignStatus['name'] ?? null);

        $sortInstant = $leadCreatedAt ?? $contactCreatedAt ?? $itemCreatedAt ?? $leadUpdatedAt ?? $contactUpdatedAt ?? $itemUpdatedAt;
        $bestUpdatedAt = $leadUpdatedAt ?? $contactUpdatedAt ?? $itemUpdatedAt ?? $sortInstant;

        $leadData = [
            'lead_id' => $leadId ?? 0,
            'name' => is_string($lead['name'] ?? null) ? trim($lead['name']) : '',
            'whatsapp' => $whatsapp,
            'status' => [
                'id' => $this->normalizeInt($status['id'] ?? null) ?? 0,
                'name' => $status['name'] ?? '',
                'label_pt' => $this->translateLeadStatus($statusName),
            ],
            'created_at' => $this->formatDate($primaryCreatedAt),
            'updated_at' => $this->formatDate($bestUpdatedAt),
            'campaign_item' => [
                'id' => $this->normalizeInt($item['id'] ?? null) ?? 0,
                'status' => [
                    'id' => $this->normalizeInt($campaignStatus['id'] ?? null) ?? 0,
                    'name' => $campaignStatus['name'] ?? '',
                    'label_pt' => $this->translateCampaignStatus($campaignStatusName),
                ],
            ],
        ];

        return [
            'dedupe_key' => $dedupeKey,
            'lead' => $leadData,
            'sort_instant' => $sortInstant,
            'created_at_dt' => $primaryCreatedAt,
            'updated_at_dt' => $bestUpdatedAt,
            'status_name' => $statusName,
            'whatsapp_ddd' => $this->extractDdd($whatsapp),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function shouldReplaceRecord(array $candidate, array $current): bool
    {
        $candidateInstant = $candidate['sort_instant'];
        $currentInstant = $current['sort_instant'];

        if ($candidateInstant !== null && $currentInstant !== null) {
            if ($candidateInstant > $currentInstant) {
                return true;
            }
            if ($candidateInstant < $currentInstant) {
                return false;
            }
        } elseif ($candidateInstant !== null) {
            return true;
        } elseif ($currentInstant !== null) {
            return false;
        }

        $candidateUpdated = $candidate['updated_at_dt'];
        $currentUpdated = $current['updated_at_dt'];

        if ($candidateUpdated !== null && $currentUpdated !== null) {
            if ($candidateUpdated > $currentUpdated) {
                return true;
            }
            if ($candidateUpdated < $currentUpdated) {
                return false;
            }
        } elseif ($candidateUpdated !== null) {
            return true;
        } elseif ($currentUpdated !== null) {
            return false;
        }

        $candidateLeadId = $candidate['lead']['lead_id'] ?? 0;
        $currentLeadId = $current['lead']['lead_id'] ?? 0;

        return $candidateLeadId >= $currentLeadId;
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $string = is_string($value) ? trim($value) : (string) $value;
        if ($string === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($string, $this->appTimezone);
        } catch (Exception) {
            return null;
        }
    }

    private function normalizeStatusName(mixed $value): string
    {
        if (!is_string($value)) {
            return 'unknown';
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? 'unknown' : $normalized;
    }

    private function translateLeadStatus(string $status): string
    {
        if ($status === 'unknown') {
            return 'Desconhecido';
        }

        return self::LEAD_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    private function translateCampaignStatus(string $status): string
    {
        if ($status === 'unknown') {
            return 'Desconhecido';
        }

        return self::CAMPAIGN_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    private function extractWhatsapp(array $lead, array $contact): string
    {
        $candidates = [
            $lead['whatsapp'] ?? null,
            $lead['phone'] ?? null,
            $lead['mobile'] ?? null,
            $contact['whatsapp'] ?? null,
            $contact['phone'] ?? null,
            $contact['contact'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', $candidate);
            if ($digits !== null && $digits !== '') {
                return $digits;
            }
        }

        return '';
    }

    private function extractDdd(string $phone): ?string
    {
        if ($phone === '') {
            return null;
        }

        $normalized = $phone;

        if (str_starts_with($normalized, '55') && strlen($normalized) > 11) {
            $normalized = substr($normalized, 2);
        }

        if (strlen($normalized) < 2) {
            return null;
        }

        return substr($normalized, 0, 2);
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function formatDate(?DateTimeImmutable $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->setTimezone($this->utcTimezone)->format('Y-m-d\TH:i:s\Z');
    }
}
