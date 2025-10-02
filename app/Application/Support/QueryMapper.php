<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\DTO\QueryOptions;
use App\Domain\Exception\ValidationException;

final class QueryMapper
{
    private const MAX_PAGE_SIZE = 200;
    private const DEFAULT_PAGE_SIZE = 50;
    private const DEFAULT_PAGE_NUMBER = 1;

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
    private const SOLD_ITEMS_FILTERS = [
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
        $page = $this->normalizePage($queryParams['page']['number'] ?? null);
        $size = $this->normalizeSize($queryParams['page']['size'] ?? null);
        $all = $this->normalizeBoolean($queryParams['all'] ?? null);

        $crmQuery = ['page' => $page, 'per_page' => $size];

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $crmQuery += $this->mapOrderFilters($filters);

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions($crmQuery, $page, $size, $all, $sort, $fields);
    }

    public function mapSoldItems(array $queryParams): QueryOptions
    {
        $page = $this->normalizePage($queryParams['page']['number'] ?? null);
        $size = $this->normalizeSize($queryParams['page']['size'] ?? null);
        $all = $this->normalizeBoolean($queryParams['all'] ?? null);

        $crmQuery = ['page' => $page, 'per_page' => $size];
        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $crmQuery += $this->mapSoldItemsFilters($filters);

        $sort = $this->parseSort($queryParams['sort'] ?? null);

        return new QueryOptions($crmQuery, $page, $size, $all, $sort, []);
    }

    public function mapCampaignSchedule(array $queryParams): QueryOptions
    {
        $page = $this->normalizePage($queryParams['page']['number'] ?? null);
        $size = $this->normalizeSize($queryParams['page']['size'] ?? null);
        $all = $this->normalizeBoolean($queryParams['all'] ?? null);

        $crmQuery = ['page' => $page, 'per_page' => $size];
        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        foreach (self::CAMPAIGN_FILTERS as $key => $target) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if (is_array($value)) {
                continue;
            }
            $normalized = $this->sanitizeScalar($value);
            if ($normalized !== '') {
                $crmQuery[$target] = $normalized;
            }
        }

        return new QueryOptions($crmQuery, $page, $size, $all, [], []);
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
                if (isset($value['in'])) {
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
                if ($normalized !== '') {
                    $mapped[$target] = '%' . $normalized . '%';
                }
            } elseif (!is_array($value)) {
                $normalized = $this->sanitizeScalar($value);
                if ($normalized !== '') {
                    $mapped[$target] = '%' . $normalized . '%';
                }
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

        foreach (self::SOLD_ITEMS_FILTERS as $source => $target) {
            if (!array_key_exists($source, $filters)) {
                continue;
            }

            $value = $filters[$source];
            if (is_array($value)) {
                if (isset($value['in'])) {
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

    /**
     * @param array<string, mixed> $fields
     * @return array<string, array<int, string>>
     */
    private function parseFields(array $fields): array
    {
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

    private function normalizePage(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_PAGE_NUMBER;
        }

        $page = (int) $value;
        if ($page < 1) {
            throw new ValidationException([
                ['field' => 'page[number]', 'message' => 'deve ser maior ou igual a 1'],
            ]);
        }

        return $page;
    }

    private function normalizeSize(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_PAGE_SIZE;
        }

        $size = (int) $value;
        if ($size < 1) {
            throw new ValidationException([
                ['field' => 'page[size]', 'message' => 'deve ser maior ou igual a 1'],
            ]);
        }

        if ($size > self::MAX_PAGE_SIZE) {
            throw new ValidationException([
                ['field' => 'page[size]', 'message' => 'máximo ' . self::MAX_PAGE_SIZE],
            ]);
        }

        return $size;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [];
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
}
