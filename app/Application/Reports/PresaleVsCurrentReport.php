<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateTimeImmutable;
use Respect\Validation\Validator as v;

final class PresaleVsCurrentReport extends BaseReport
{
    private const MAX_PAGES = 10;

    public function key(): string
    {
        return 'orders.presale_vs_current';
    }

    public function title(): string
    {
        return 'Comparativo de pr?-venda vs. ano atual por pacote';
    }

    /**
     * @return array<string, \Respect\Validation\Validatable>
     */
    public function rules(): array
    {
        return [
            'current_year' => v::optional(v::intType()->between(2000, 2100)),
            'previous_year' => v::optional(v::intType()->between(2000, 2100)),
            'current_start' => v::optional(v::date('Y-m-d')),
            'current_end' => v::optional(v::date('Y-m-d')),
            'previous_start' => v::optional(v::date('Y-m-d')),
            'previous_end' => v::optional(v::date('Y-m-d')),
            'product[slug]' => v::optional(v::stringType()->length(1, 100)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        return [
            'include' => 'items,customer',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [
            'package',
            'current_revenue',
            'previous_revenue',
            'revenue_delta',
            'revenue_delta_percent',
            'current_count',
            'previous_count',
            'count_delta',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return ['revenue_delta', 'current_revenue', 'revenue_delta_percent', 'count_delta'];
    }

    public function cacheTtl(): int
    {
        return 1800;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function run(array $filters): ReportResult
    {
        $traceId = $this->extractTraceId($filters);
        $startedAt = microtime(true);

        $this->logger->info('Running report orders.presale_vs_current (start)', [
            'trace_id' => $traceId,
        ]);

        $currentYear = isset($filters['current_year']) ? (int) $filters['current_year'] : (int) date('Y');
        $previousYear = isset($filters['previous_year']) ? (int) $filters['previous_year'] : $currentYear - 1;
        $currentStart = isset($filters['current_start']) ? (string) $filters['current_start'] : sprintf('%d-01-01', $currentYear);
        $currentEnd = isset($filters['current_end']) ? (string) $filters['current_end'] : sprintf('%d-12-31', $currentYear);
        $previousStart = isset($filters['previous_start']) ? (string) $filters['previous_start'] : sprintf('%d-01-01', $previousYear);
        $previousEnd = isset($filters['previous_end']) ? (string) $filters['previous_end'] : sprintf('%d-12-31', $previousYear);

        unset($filters['current_year'], $filters['previous_year'], $filters['current_start'], $filters['current_end'], $filters['previous_start'], $filters['previous_end']);

        $filters = $this->normalizeFilters($filters, $this->defaultFilters());
        $filters = $this->mergeIncludes($filters, ['items', 'customer']);

        $pagination = $this->resolvePagination($filters);
        $sortField = isset($filters['sort']) && is_string($filters['sort']) && in_array($filters['sort'], $this->sortable(), true)
            ? $filters['sort']
            : 'revenue_delta';
        $direction = isset($filters['dir']) && is_string($filters['dir']) && strtolower($filters['dir']) === 'asc'
            ? 'asc'
            : 'desc';

        unset($filters['sort'], $filters['dir']);

        $cacheFilters = $filters;
        $cacheFilters['_sort'] = $sortField;
        $cacheFilters['_dir'] = $direction;
        $cacheFilters['_current'] = [$currentStart, $currentEnd];
        $cacheFilters['_previous'] = [$previousStart, $previousEnd];

        $result = $this->remember($cacheFilters, $this->cacheTtl(), function () use ($filters, $traceId, $currentStart, $currentEnd, $previousStart, $previousEnd, $sortField, $direction, $pagination) {
            $currentFilters = $filters;
            $currentFilters['order[created-start]'] = $currentStart;
            $currentFilters['order[created-end]'] = $currentEnd;
            $currentFilters['per_page'] = $pagination['per_page'];

            $previousFilters = $filters;
            $previousFilters['order[created-start]'] = $previousStart;
            $previousFilters['order[created-end]'] = $previousEnd;
            $previousFilters['per_page'] = $pagination['per_page'];

            $currentOrders = $this->collectOrders($currentFilters, $traceId);
            $previousOrders = $this->collectOrders($previousFilters, $traceId);

            $currentAggregate = $this->aggregateByPackage(
                $currentOrders,
                fn (array $order): string => $this->resolvePackageName($order),
                fn (array $order): array => [
                    'count' => 1.0,
                    'revenue' => $this->resolveOrderTotal($order),
                ]
            );

            $previousAggregate = $this->aggregateByPackage(
                $previousOrders,
                fn (array $order): string => $this->resolvePackageName($order),
                fn (array $order): array => [
                    'count' => 1.0,
                    'revenue' => $this->resolveOrderTotal($order),
                ]
            );

            $packages = array_unique(array_merge(array_keys($currentAggregate), array_keys($previousAggregate)));
            $rows = [];

            foreach ($packages as $package) {
                $currentData = $currentAggregate[$package] ?? ['count' => 0.0, 'revenue' => 0.0];
                $previousData = $previousAggregate[$package] ?? ['count' => 0.0, 'revenue' => 0.0];

                $currentRevenue = (float) ($currentData['revenue'] ?? 0.0);
                $previousRevenue = (float) ($previousData['revenue'] ?? 0.0);
                $revenueDelta = $currentRevenue - $previousRevenue;
                $currentCount = (int) round($currentData['count'] ?? 0.0);
                $previousCount = (int) round($previousData['count'] ?? 0.0);

                $rows[] = [
                    'package' => $package,
                    'current_revenue' => round($currentRevenue, 2),
                    'previous_revenue' => round($previousRevenue, 2),
                    'revenue_delta' => round($revenueDelta, 2),
                    'revenue_delta_percent' => round($this->percent($revenueDelta, $previousRevenue), 2),
                    'current_count' => $currentCount,
                    'previous_count' => $previousCount,
                    'count_delta' => $currentCount - $previousCount,
                ];
            }

            $rows = $this->sortData($rows, $sortField, $direction);

            $summary = [
                'current_revenue_total' => round(array_sum(array_column($rows, 'current_revenue')), 2),
                'previous_revenue_total' => round(array_sum(array_column($rows, 'previous_revenue')), 2),
                'current_orders' => array_sum(array_column($rows, 'current_count')),
                'previous_orders' => array_sum(array_column($rows, 'previous_count')),
            ];

            $meta = [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'count' => count($rows),
                'total' => count($rows),
                'cache_hit' => false,
                'periods' => [
                    'current' => ['start' => $currentStart, 'end' => $currentEnd],
                    'previous' => ['start' => $previousStart, 'end' => $previousEnd],
                ],
            ];

            $data = array_slice($rows, ($pagination['page'] - 1) * $pagination['per_page'], $pagination['per_page']);

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

        $this->logger->info('Running report orders.presale_vs_current (finish)', [
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'] ?? false,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectOrders(array $filters, string $traceId): array
    {
        $collected = [];
        $query = $filters;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : self::MAX_PER_PAGE;

        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        for ($currentPage = $page; $currentPage < $page + self::MAX_PAGES; $currentPage++) {
            $query['page'] = $currentPage;
            $query['per_page'] = $perPage;

            $response = $this->apiClient->searchOrders($query, $traceId);
            $body = $response['body'] ?? [];
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
            if ($data === []) {
                break;
            }

            $collected = array_merge($collected, $data);

            $links = $body['links'] ?? [];
            if (!isset($links['next']) || !is_string($links['next']) || $links['next'] === '') {
                break;
            }

            $nextQuery = $this->extractQueryFromLink($links['next']);
            if ($nextQuery === null) {
                break;
            }

            $query = array_merge($query, $nextQuery);
        }

        return $collected;
    }

    private function resolvePackageName(array $order): string
    {
        $item = $order['items'][0] ?? [];
        $package = $item['package']['name'] ?? ($item['product']['package']['name'] ?? null);
        if (is_string($package) && $package !== '') {
            return $package;
        }

        $product = $item['product']['name'] ?? ($order['product']['name'] ?? 'Indefinido');

        return is_string($product) && $product !== '' ? $product : 'Indefinido';
    }

    private function resolveOrderTotal(array $order): float
    {
        $total = $order['totals']['grand_total'] ?? ($order['totals']['total'] ?? ($order['total'] ?? 0));
        if (is_numeric($total)) {
            return (float) $total;
        }

        return 0.0;
    }
}
