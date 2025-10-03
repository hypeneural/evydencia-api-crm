<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateInterval;
use DateTimeImmutable;
use Respect\Validation\Validator as v;

final class ParticipantsUnder8Report extends BaseReport
{
    public function key(): string
    {
        return 'participants.under_8';
    }

    public function title(): string
    {
        return 'Participantes com menos de 8 anos';
    }

    /**
     * @return array<string, \Respect\Validation\Validatable>
     */
    public function rules(): array
    {
        return [
            'product[slug]' => v::optional(v::stringType()->length(1, 100)),
            'order[created-start]' => v::optional(v::date('Y-m-d')),
            'order[created-end]' => v::optional(v::date('Y-m-d')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval('P365D'));

        return [
            'order[created-start]' => $start->format('Y-m-d'),
            'order[created-end]' => $end->format('Y-m-d'),
            'include' => 'participants,customer,items',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [
            'participant_name',
            'age',
            'birthdate',
            'order_uuid',
            'customer_name',
            'customer_whatsapp',
            'product',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return ['age', 'participant_name', 'birthdate'];
    }

    public function cacheTtl(): int
    {
        return 1200;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function run(array $filters): ReportResult
    {
        $traceId = $this->extractTraceId($filters);
        $startedAt = microtime(true);

        $this->logger->info('Running report participants.under_8 (start)', [
            'trace_id' => $traceId,
        ]);

        $filters = $this->normalizeFilters($filters, $this->defaultFilters());
        $pagination = $this->resolvePagination($filters);

        $sortField = isset($filters['sort']) && is_string($filters['sort']) && in_array($filters['sort'], $this->sortable(), true)
            ? $filters['sort']
            : null;
        $direction = isset($filters['dir']) && is_string($filters['dir']) && strtolower($filters['dir']) === 'desc'
            ? 'desc'
            : 'asc';

        unset($filters['sort'], $filters['dir']);

        $filters['page'] = $pagination['page'];
        $filters['per_page'] = $pagination['per_page'];
        $filters = $this->mergeIncludes($filters, ['participants', 'customer', 'items']);

        $cacheFilters = $filters;
        $cacheFilters['_sort'] = $sortField;
        $cacheFilters['_dir'] = $direction;

        $result = $this->remember($cacheFilters, $this->cacheTtl(), function () use ($filters, $traceId, $sortField, $direction, $pagination) {
            $response = $this->apiClient->searchOrders($filters, $traceId);
            $body = $response['body'] ?? [];
            $items = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $rows = [];
            $totalParticipants = 0;
            $underEight = 0;

            foreach ($items as $order) {
                $participants = $order['participants'] ?? [];
                if (!is_array($participants) || $participants === []) {
                    continue;
                }

                foreach ($participants as $participant) {
                    ++$totalParticipants;
                    $birthdate = $participant['birthdate'] ?? ($participant['birth'] ?? null);
                    $age = self::calculateAge($birthdate);

                    if ($age === null || $age >= 8) {
                        continue;
                    }

                    ++$underEight;
                    $item = $order['items'][0] ?? [];

                    $rows[] = [
                        'participant_name' => (string) ($participant['name'] ?? ''),
                        'age' => $age,
                        'birthdate' => is_string($birthdate) ? $birthdate : null,
                        'order_uuid' => (string) ($order['uuid'] ?? ''),
                        'customer_name' => (string) ($order['customer']['name'] ?? ''),
                        'customer_whatsapp' => (string) ($order['customer']['whatsapp'] ?? ''),
                        'product' => (string) ($item['product']['name'] ?? ''),
                    ];
                }
            }

            $rows = $this->sortData($rows, $sortField, $direction);

            $meta = $body['meta'] ?? [];
            $meta['page'] = $pagination['page'];
            $meta['per_page'] = $pagination['per_page'];
            $meta['total'] = $meta['total'] ?? count($rows);
            $meta['count'] = count($rows);
            $meta['cache_hit'] = false;

            $summary = [
                'under_8' => $underEight,
                'total_participants_checked' => $totalParticipants,
                'percent_under_8' => $totalParticipants > 0 ? round(($underEight / $totalParticipants) * 100, 2) : 0,
            ];

            return new ReportResult($rows, $summary, $meta, $this->columns());
        });

        $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? false;
        $result->meta['page'] = $result->meta['page'] ?? $pagination['page'];
        $result->meta['per_page'] = $result->meta['per_page'] ?? $pagination['per_page'];
        $result->meta['count'] = $result->meta['count'] ?? count($result->data);
        $result->meta['total'] = $result->meta['total'] ?? $result->meta['count'];
        $result->meta['source'] = $result->meta['source'] ?? 'crm';

        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);
        $result->meta['took_ms'] = $result->meta['took_ms'] ?? $tookMs;

        $this->logger->info('Running report participants.under_8 (finish)', [
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'] ?? false,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }

    private static function calculateAge(mixed $birthdate): ?int
    {
        if ($birthdate instanceof DateTimeImmutable) {
            $date = $birthdate;
        } elseif (is_string($birthdate) && $birthdate !== '') {
            try {
                $date = new DateTimeImmutable($birthdate);
            } catch (\Exception) {
                return null;
            }
        } else {
            return null;
        }

        $now = new DateTimeImmutable('today');
        return (int) $date->diff($now)->format('%y');
    }
}
