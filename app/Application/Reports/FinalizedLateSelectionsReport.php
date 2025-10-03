<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateTimeImmutable;
use DateTimeInterface;
use Respect\Validation\Validator as v;

final class FinalizedLateSelectionsReport extends BaseReport
{
    public function key(): string
    {
        return 'orders.finalized_late_selections';
    }

    public function title(): string
    {
        return 'Sele??es finalizadas h? mais de 10 dias sem fechamento';
    }

    /**
     * @return array<string, \Respect\Validation\Validatable>
     */
    public function rules(): array
    {
        return [
            'order[selection-start]' => v::optional(v::date('Y-m-d')),
            'order[selection-end]' => v::optional(v::date('Y-m-d')),
            'order[status]' => v::optional(v::stringType()->length(2, 64)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        return [
            'order[status]' => 'selection_schedule_confirmed',
            'order[selection-end]' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
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
            'selection_end',
            'session_end',
            'days_since_selection',
            'status',
            'product',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return ['selection_end', 'customer_name', 'days_since_selection'];
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

        $this->logger->info('Running report orders.finalized_late_selections (start)', [
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

            $now = new DateTimeImmutable('now');

            $filtered = array_filter($items, static function ($order) use ($now): bool {
                $selectionEndRaw = $order['selection']['end'] ?? ($order['selection_end'] ?? null);
                if ($selectionEndRaw === null) {
                    return false;
                }

                $selectionEnd = self::parseDate($selectionEndRaw);
                if ($selectionEnd === null) {
                    return false;
                }

                $days = (int) $selectionEnd->diff($now)->format('%a');
                if ($days < 10) {
                    return false;
                }

                $status = (string) ($order['status'] ?? '');
                if ($status === '' || in_array($status, ['closed', 'finished', 'cancelled'], true)) {
                    return false;
                }

                return true;
            });

            $data = array_map(static function ($order) use ($now): array {
                $selectionEndRaw = $order['selection']['end'] ?? ($order['selection_end'] ?? null);
                $selectionEnd = self::parseDate($selectionEndRaw);
                $selectionEndFormatted = $selectionEnd?->format(DateTimeInterface::ATOM);
                $sessionEnd = $order['session']['end'] ?? ($order['session_end'] ?? null);
                $sessionEndDate = self::parseDate($sessionEnd);
                $sessionEndFormatted = $sessionEndDate?->format(DateTimeInterface::ATOM);

                $daysSince = $selectionEnd instanceof DateTimeImmutable
                    ? (int) $selectionEnd->diff($now)->format('%a')
                    : null;

                $item = $order['items'][0] ?? [];

                return [
                    'uuid' => (string) ($order['uuid'] ?? ''),
                    'customer_name' => (string) ($order['customer']['name'] ?? ''),
                    'selection_end' => $selectionEndFormatted,
                    'session_end' => $sessionEndFormatted,
                    'days_since_selection' => $daysSince,
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
                'avg_days_since_selection' => $data === [] ? 0 : array_sum(array_map(static fn ($row): int => (int) ($row['days_since_selection'] ?? 0), $data)) / max(count($data), 1),
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

        $this->logger->info('Running report orders.finalized_late_selections (finish)', [
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'] ?? false,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
