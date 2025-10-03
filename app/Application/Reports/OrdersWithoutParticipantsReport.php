<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Reports\ReportResult;
use Respect\Validation\Validator as v;

final class OrdersWithoutParticipantsReport extends BaseReport
{
    public function key(): string
    {
        return 'orders.without_participants';
    }

    public function title(): string
    {
        return 'Pedidos sem participantes confirmados';
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
        return [
            'order[status]' => 'payment_confirmed',
            'include' => 'participants,items,customer',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [
            'uuid',
            'customer_name',
            'customer_whatsapp',
            'product',
            'created_at',
            'schedule_1',
            'schedule_2',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return ['created_at', 'customer_name', 'product'];
    }

    public function cacheTtl(): int
    {
        return 900;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function run(array $filters): ReportResult
    {
        $traceId = $this->extractTraceId($filters);
        $startedAt = microtime(true);

        $this->logger->info('Running report orders.without_participants (start)', [
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
        $filters = $this->mergeIncludes($filters, ['participants', 'items', 'customer']);

        $cacheFilters = $filters;
        $cacheFilters['_sort'] = $sortField;
        $cacheFilters['_dir'] = $direction;

        $result = $this->remember($cacheFilters, $this->cacheTtl(), function () use ($filters, $traceId, $sortField, $direction, $pagination) {
            $response = $this->apiClient->searchOrders($filters, $traceId);
            $body = $response['body'] ?? [];
            $items = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $filtered = array_filter($items, static function ($order): bool {
                $participants = $order['participants'] ?? [];
                if (!is_array($participants) || $participants === []) {
                    return true;
                }

                foreach ($participants as $participant) {
                    if (!isset($participant['status']) || $participant['status'] === null) {
                        continue;
                    }

                    if (in_array($participant['status'], ['confirmed', 'scheduled'], true)) {
                        return false;
                    }
                }

                return true;
            });

            $data = array_map(static function ($order): array {
                $firstItem = $order['items'][0] ?? [];
                $scheduleOne = $order['schedule_1'] ?? ($order['schedule_one'] ?? null);
                $scheduleTwo = $order['schedule_2'] ?? ($order['schedule_two'] ?? null);

                return [
                    'uuid' => (string) ($order['uuid'] ?? ''),
                    'customer_name' => (string) ($order['customer']['name'] ?? ''),
                    'customer_whatsapp' => (string) ($order['customer']['whatsapp'] ?? ''),
                    'product' => (string) ($firstItem['product']['name'] ?? ''),
                    'created_at' => $order['created_at'] ?? null,
                    'schedule_1' => $scheduleOne,
                    'schedule_2' => $scheduleTwo,
                ];
            }, array_values($filtered));

            $data = $this->sortData($data, $sortField, $direction);

            $meta = $body['meta'] ?? [];
            $meta['page'] = $pagination['page'];
            $meta['per_page'] = $pagination['per_page'];
            $meta['total'] = $meta['total'] ?? count($data);
            $meta['count'] = count($data);
            $meta['cache_hit'] = false;

            return new ReportResult(
                $data,
                ['total' => count($data)],
                $meta,
                $this->columns()
            );
        });

        $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? false;
        $result->meta['page'] = $result->meta['page'] ?? $pagination['page'];
        $result->meta['per_page'] = $result->meta['per_page'] ?? $pagination['per_page'];
        $result->meta['count'] = $result->meta['count'] ?? count($result->data);
        $result->meta['total'] = $result->meta['total'] ?? $result->meta['count'];
        $result->meta['source'] = $result->meta['source'] ?? 'crm';

        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);
        $result->meta['took_ms'] = $result->meta['took_ms'] ?? $tookMs;

        $this->logger->info('Running report orders.without_participants (finish)', [
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'] ?? false,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }
}
