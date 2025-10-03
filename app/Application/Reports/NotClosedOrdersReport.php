<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateInterval;
use DateTimeImmutable;
use Respect\Validation\Validator as v;

final class NotClosedOrdersReport extends BaseReport
{
    public function key(): string
    {
        return 'orders.not_closed';
    }

    public function title(): string
    {
        return 'Pedidos com sess?o confirmada e n?o fechados';
    }

    /**
     * @return array<string, \Respect\Validation\Validatable>
     */
    public function rules(): array
    {
        return [
            'order[session-start]' => v::optional(v::date('Y-m-d')),
            'order[session-end]' => v::optional(v::date('Y-m-d')),
            'order[status]' => v::optional(v::stringType()->length(2, 64)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval('P30D'));

        return [
            'order[status]' => 'session_schedule',
            'order[session-start]' => $start->format('Y-m-d'),
            'order[session-end]' => $end->format('Y-m-d'),
            'include' => 'customer,items',
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
            'session_date',
            'status',
            'product',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return ['session_date', 'customer_name'];
    }

    public function cacheTtl(): int
    {
        return 600;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function run(array $filters): ReportResult
    {
        $traceId = $this->extractTraceId($filters);
        $startedAt = microtime(true);

        $this->logger->info('Running report orders.not_closed (start)', [
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
        $filters = $this->mergeIncludes($filters, ['customer', 'items']);

        $cacheFilters = $filters;
        $cacheFilters['_sort'] = $sortField;
        $cacheFilters['_dir'] = $direction;

        $result = $this->remember($cacheFilters, $this->cacheTtl(), function () use ($filters, $traceId, $sortField, $direction, $pagination) {
            $response = $this->apiClient->searchOrders($filters, $traceId);
            $body = $response['body'] ?? [];
            $items = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $filtered = array_filter($items, static function ($order): bool {
                $status = (string) ($order['status'] ?? '');
                if ($status === '') {
                    return false;
                }

                if (in_array($status, ['closed', 'finished', 'cancelled'], true)) {
                    return false;
                }

                $session = $order['session'] ?? [];
                $sessionDate = $session['date'] ?? ($session['start'] ?? ($order['session']['start'] ?? ($order['session_start'] ?? null)));

                return $sessionDate !== null;
            });

            $data = array_map(static function ($order): array {
                $session = $order['session'] ?? [];
                $sessionDate = $session['date'] ?? ($session['start'] ?? ($order['session']['start'] ?? ($order['session_start'] ?? null)));
                $item = $order['items'][0] ?? [];

                return [
                    'uuid' => (string) ($order['uuid'] ?? ''),
                    'customer_name' => (string) ($order['customer']['name'] ?? ''),
                    'customer_whatsapp' => (string) ($order['customer']['whatsapp'] ?? ''),
                    'session_date' => is_string($sessionDate) ? $sessionDate : null,
                    'status' => (string) ($order['status'] ?? ''),
                    'product' => (string) ($item['product']['name'] ?? ''),
                ];
            }, array_values($filtered));

            $data = $this->sortData($data, $sortField, $direction);

            $meta = $body['meta'] ?? [];
            $meta['page'] = $pagination['page'];
            $meta['per_page'] = $pagination['per_page'];
            $meta['total'] = $meta['total'] ?? count($data);
            $meta['count'] = count($data);
            $meta['cache_hit'] = false;

            $summary = [
                'total' => count($data),
            ];

            return new ReportResult($data, $summary, $meta, $this->columns());
        });

        $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? false;
        $result->meta['page'] = $result->meta['page'] ?? $pagination['page'];
        $result->meta['per_page'] = $result->meta['per_page'] ?? $pagination['per_page'];
        $result->meta['count'] = $result->meta['count'] ?? count($result->data);
        $result->meta['total'] = $result->meta['total'] ?? $result->meta['count'];
        $result->meta['source'] = $result->meta['source'] ?? 'crm';

        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);
        $result->meta['took_ms'] = $result->meta['took_ms'] ?? $tookMs;

        $this->logger->info('Running report orders.not_closed (finish)', [
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'] ?? false,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }
}
