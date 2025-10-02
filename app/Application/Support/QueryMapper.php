<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\ValidationException;

final class QueryMapper
{
    private const MAX_PAGE_SIZE = 200;
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PER_PAGE = 50;

    /**
     * @var array<string, string>
     */
    private const ORDER_EQUAL_FILTERS = [
        'uuid' => 'order[uuid]',
        'status' => 'order[status]',
        'customer_id' => 'customer[id]',
        'customer_uuid' => 'customer[uuid]',
        'customer_email' => 'customer[email]',
        'customer_whatsapp' => 'customer[whatsapp]',
        'product_uuid' => 'product[uuid]',
        'product_name' => 'product[name]',
        'product_slug' => 'product[slug]',
        'product_ref' => 'product[reference]',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const ORDER_RANGE_FILTERS = [
        'created_at' => [
            'gte' => 'order[created-start]',
            'lte' => 'order[created-end]',
        ],
        'session_at' => [
            'gte' => 'order[session-start]',
            'lte' => 'order[session-end]',
        ],
        'selection_at' => [
            'gte' => 'order[selection-start]',
            'lte' => 'order[selection-end]',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const ORDER_LIKE_FILTERS = [
        'customer_name' => 'customer[name]',
    ];

    /**
     * @var array<string, string>
     */
    private const ORDER_TOP_LEVEL_EQUAL = [
        'status' => 'order[status]',
        'customer_uuid' => 'customer[uuid]',
        'customer_email' => 'customer[email]',
        'customer_whatsapp' => 'customer[whatsapp]',
        'product_uuid' => 'product[uuid]',
        'product_name' => 'product[name]',
        'product_slug' => 'product[slug]',
        'product_ref' => 'product[reference]',
    ];

    /**
     * @var array<string, string>
     */
    private const ORDER_TOP_LEVEL_RANGE = [
        'created_start' => 'order[created-start]',
        'created_end' => 'order[created-end]',
        'session_start' => 'order[session-start]',
        'session_end' => 'order[session-end]',
        'selection_start' => 'order[selection-start]',
        'selection_end' => 'order[selection-end]',
    ];

    /**
     * @var array<string, string>
     */
    private const ORDER_TOP_LEVEL_LIKE = [
        'customer_name' => 'customer[name]',
    ];

    /**
     * @var array<string, string>
     */
    private const SOLD_ITEMS_EQUAL_FILTERS = [
        'item_name' => 'item[name]',
        'item_slug' => 'item[slug]',
        'item_ref' => 'item[ref]',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const SOLD_ITEMS_RANGE_FILTERS = [
        'created_at' => [
            'gte' => 'order[created-start]',
            'lte' => 'order[created-end]',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const CAMPAIGN_FILTERS = [
        'campaign_id' => 'campaign[id]',
        'contact_phone' => 'contacts[phone]',
    ];

    public function mapOrdersSearch(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $crmQuery = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $crmQuery += $this->mapOrderFilters($filters);
        $crmQuery += $this->mapTopLevelEquality($queryParams, self::ORDER_TOP_LEVEL_EQUAL);
        $crmQuery += $this->mapTopLevelRange($queryParams, self::ORDER_TOP_LEVEL_RANGE);
        $crmQuery += $this->mapTopLevelLike($queryParams, self::ORDER_TOP_LEVEL_LIKE);

        $crmQuery += $this->mapPassThrough($filters);
        $crmQuery += $this->mapPassThrough($queryParams);

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        if ($sort !== []) {
            $crmQuery['sort'] = implode(',', array_map(
                static fn (array $rule): string => $rule['field'] . ':' . $rule['direction'],
                $sort
            ));
        }

        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, $fields);
    }

    public function mapSoldItems(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $crmQuery = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $crmQuery += $this->mapSoldItemsFilters($filters);
        $crmQuery += $this->mapTopLevelEquality($queryParams, self::SOLD_ITEMS_EQUAL_FILTERS);
        $crmQuery += $this->mapPassThrough($filters);
        $crmQuery += $this->mapPassThrough($queryParams);

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        if ($sort !== []) {
            $crmQuery['sort'] = implode(',', array_map(
                static fn (array $rule): string => $rule['field'] . ':' . $rule['direction'],
                $sort
            ));
        }

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, []);
    }

    public function mapCampaignSchedule(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $crmQuery = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $crmQuery += $this->mapTopLevelEquality($filters, self::CAMPAIGN_FILTERS);
        $crmQuery += $this->mapTopLevelEquality($queryParams, self::CAMPAIGN_FILTERS);
        $crmQuery += $this->mapPassThrough($filters);
        $crmQuery += $this->mapPassThrough($queryParams);

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        if ($sort !== []) {
            $crmQuery['sort'] = implode(',', array_map(
                static fn (array $rule): string => $rule['field'] . ':' . $rule['direction'],
                $sort
            ));
        }

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, []);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mapOrderFilters(array $filters): array
    {
        $mapped = [];

        foreach (self::ORDER_EQUAL_FILTERS as $source => $target) {
            if (!array_key_exists($source, $filters)) {
                continue;
            }

            $value = $filters[$source];
            if (is_array($value)) {
                if (isset($value['eq'])) {
                    $normalizedEq = $this->sanitizeScalar($value['eq']);
                    if ($normalizedEq !== '') {
                        $mapped[$target] = $normalizedEq;
                    }
                } elseif (isset($value['in'])) {
                    $list = $this->normalizeList($value['in']);
                    if ($list !== '') {
                        $mapped[$target] = $list;
                    }
                }
                continue;
            }

            $normalized = $this->sanitizeScalar($value);
            if ($normalized !== '') {
                $mapped[$target] = $normalized;
            }
        }

        foreach (self::ORDER_RANGE_FILTERS as $source => $operators) {
            if (!isset($filters[$source]) || !is_array($filters[$source])) {
                continue;
            }

            foreach ($operators as $operator => $target) {
                if (!isset($filters[$source][$operator])) {
                    continue;
                }
                $normalized = $this->sanitizeScalar($filters[$source][$operator]);
                if ($normalized !== '') {
                    $mapped[$target] = $normalized;
                }
            }
        }

        foreach (self::ORDER_LIKE_FILTERS as $source => $target) {
            if (!isset($filters[$source])) {
                continue;
            }

            $value = $filters[$source];
            if (is_array($value) && isset($value['like'])) {
                $normalized = $this->sanitizeScalar($value['like']);
            } elseif (is_array($value) && isset($value['eq'])) {
                $normalized = $this->sanitizeScalar($value['eq']);
            } else {
                $normalized = $this->sanitizeScalar($value);
            }

            if ($normalized !== '') {
                $mapped[$target] = '%' . $normalized . '%';
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mapSoldItemsFilters(array $filters): array
    {
        $mapped = [];

        foreach (self::SOLD_ITEMS_EQUAL_FILTERS as $source => $target) {
            if (!array_key_exists($source, $filters)) {
                continue;
            }

            $value = $filters[$source];
            if (is_array($value)) {
                if (isset($value['eq'])) {
                    $normalizedEq = $this->sanitizeScalar($value['eq']);
                    if ($normalizedEq !== '') {
                        $mapped[$target] = $normalizedEq;
                    }
                } elseif (isset($value['in'])) {
                    $list = $this->normalizeList($value['in']);
                    if ($list !== '') {
                        $mapped[$target] = $list;
                    }
                }
                continue;
            }

            $normalized = $this->sanitizeScalar($value);
            if ($normalized !== '') {
                $mapped[$target] = $normalized;
            }
        }

        foreach (self::SOLD_ITEMS_RANGE_FILTERS as $source => $operators) {
            if (!isset($filters[$source]) || !is_array($filters[$source])) {
                continue;
            }

            foreach ($operators as $operator => $target) {
                if (!isset($filters[$source][$operator])) {
                    continue;
                }
                $normalized = $this->sanitizeScalar($filters[$source][$operator]);
                if ($normalized !== '') {
                    $mapped[$target] = $normalized;
                }
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, string> $mapping
     * @return array<string, string>
     */
    private function mapTopLevelEquality(array $params, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $alias => $target) {
            if (!array_key_exists($alias, $params)) {
                continue;
            }

            $value = $params[$alias];
            if (is_array($value)) {
                if (isset($value['eq'])) {
                    $normalized = $this->sanitizeScalar($value['eq']);
                    if ($normalized !== '') {
                        $mapped[$target] = $normalized;
                    }
                }
                continue;
            }

            $normalized = $this->sanitizeScalar($value);
            if ($normalized !== '') {
                $mapped[$target] = $normalized;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, string> $mapping
     * @return array<string, string>
     */
    private function mapTopLevelRange(array $params, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $alias => $target) {
            if (!array_key_exists($alias, $params)) {
                continue;
            }

            $value = $params[$alias];
            if (is_array($value)) {
                if (isset($value['eq'])) {
                    $normalized = $this->sanitizeScalar($value['eq']);
                } else {
                    $normalized = '';
                }
            } else {
                $normalized = $this->sanitizeScalar($value);
            }

            if ($normalized !== '') {
                $mapped[$target] = $normalized;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, string> $mapping
     * @return array<string, string>
     */
    private function mapTopLevelLike(array $params, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $alias => $target) {
            if (!array_key_exists($alias, $params)) {
                continue;
            }

            $value = $params[$alias];
            if (is_array($value)) {
                if (isset($value['like'])) {
                    $normalized = $this->sanitizeScalar($value['like']);
                } elseif (isset($value['eq'])) {
                    $normalized = $this->sanitizeScalar($value['eq']);
                } else {
                    $normalized = '';
                }
            } else {
                $normalized = $this->sanitizeScalar($value);
            }

            if ($normalized !== '') {
                $mapped[$target] = '%' . $normalized . '%';
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mapPassThrough(array $params): array
    {
        $mapped = [];

        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array($key, ['filter', 'page', 'per_page', 'page[number]', 'page[size]', 'all', 'fetch', 'sort', 'fields'], true)) {
                continue;
            }

            if ($key === 'q') {
                $normalized = $this->sanitizeScalar($value);
                if ($normalized !== '') {
                    $mapped['q'] = $normalized;
                }
                continue;
            }

            if (!str_contains($key, '[')) {
                continue;
            }

            if (is_array($value)) {
                $mapped[$key] = $value;
                continue;
            }

            $normalized = $this->sanitizeScalar($value);
            if ($normalized !== '') {
                $mapped[$key] = $normalized;
            }
        }

        return $mapped;
    }

    /**
     * @return array{int, int}
     */
    private function resolvePagination(array $queryParams): array
    {
        $pageCandidate = $queryParams['page'] ?? null;
        if (is_array($pageCandidate)) {
            $pageCandidate = $pageCandidate['number'] ?? null;
        }

        $perPageCandidate = $queryParams['per_page'] ?? null;
        if (is_array($queryParams['page'] ?? null)) {
            $perPageCandidate = $perPageCandidate ?? ($queryParams['page']['size'] ?? null);
        }

        $page = $this->normalizePage($pageCandidate);
        $perPage = $this->normalizePerPage($perPageCandidate);

        return [$page, $perPage];
    }

    private function normalizePage(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_PAGE;
        }

        $page = (int) $value;
        if ($page < 1) {
            throw new ValidationException([
                ['field' => 'page', 'message' => 'deve ser maior ou igual a 1'],
            ]);
        }

        return $page;
    }

    private function normalizePerPage(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_PER_PAGE;
        }

        $perPage = (int) $value;
        if ($perPage < 1) {
            throw new ValidationException([
                ['field' => 'per_page', 'message' => 'deve ser maior ou igual a 1'],
            ]);
        }

        if ($perPage > self::MAX_PAGE_SIZE) {
            throw new ValidationException([
                ['field' => 'per_page', 'message' => 'maximo ' . self::MAX_PAGE_SIZE],
            ]);
        }

        return $perPage;
    }

    private function normalizeFetch(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'all'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed>|string $fields
     * @return array<string, array<int, string>>
     */
    private function parseFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $list = array_filter(array_map('trim', explode(',', $fields)), static fn (string $field): bool => $field !== '');
            return $list === [] ? [] : ['default' => array_values($list)];
        }

        if (!is_array($fields)) {
            return [];
        }

        $result = [];

        foreach ($fields as $resource => $value) {
            if (!is_string($value)) {
                continue;
            }

            $list = array_filter(array_map('trim', explode(',', $value)), static fn (string $field): bool => $field !== '');
            if ($list !== []) {
                $result[$resource] = array_values($list);
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function sanitizeScalar(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function normalizeList(mixed $value): string
    {
        if (is_string($value)) {
            $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
            return implode(',', $items);
        }

        if (is_array($value)) {
            $items = array_filter(array_map(
                fn ($item): string => $this->sanitizeScalar($item),
                $value
            ), static fn (string $item): bool => $item !== '');

            return implode(',', $items);
        }

        return '';
    }

    /**
     * @return array<int, array{field: string, direction: string}>
     */
    private function parseSort(mixed $sort): array
    {
        if (!is_string($sort) || trim($sort) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $sort)));
        $result = [];

        foreach ($parts as $part) {
            $direction = 'asc';
            $field = $part;
            if (str_starts_with($part, '-')) {
                $direction = 'desc';
                $field = substr($part, 1);
            }

            if ($field === '') {
                continue;
            }

            $result[] = [
                'field' => $field,
                'direction' => $direction,
            ];
        }

        return $result;
    }
}



