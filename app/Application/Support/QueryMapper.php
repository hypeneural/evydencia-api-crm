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
        'order_id' => 'order[id]',
        'uuid' => 'order[uuid]',
        'status' => 'order[status]',
        'customer_id' => 'customer[id]',
        'customer_uuid' => 'customer[uuid]',
        'customer_email' => 'customer[email]',
        'customer_whatsapp' => 'customer[whatsapp]',
        'customer_document' => 'customer[document]',
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
        'order_id' => 'order[id]',
        'status' => 'order[status]',
        'customer_uuid' => 'customer[uuid]',
        'customer_email' => 'customer[email]',
        'customer_whatsapp' => 'customer[whatsapp]',
        'customer_document' => 'customer[document]',
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

    private const ORDER_STRUCTURED_MAPPING = [
        'order' => [
            'id' => ['alias' => 'order_id', 'type' => 'equal'],
            'uuid' => ['alias' => 'uuid', 'type' => 'equal'],
            'status' => ['alias' => 'status', 'type' => 'equal'],
            'created-start' => ['alias' => 'created_at', 'type' => 'range', 'operator' => 'gte'],
            'created-end' => ['alias' => 'created_at', 'type' => 'range', 'operator' => 'lte'],
            'session-start' => ['alias' => 'session_at', 'type' => 'range', 'operator' => 'gte'],
            'session-end' => ['alias' => 'session_at', 'type' => 'range', 'operator' => 'lte'],
            'selection-start' => ['alias' => 'selection_at', 'type' => 'range', 'operator' => 'gte'],
            'selection-end' => ['alias' => 'selection_at', 'type' => 'range', 'operator' => 'lte'],
        ],
        'customer' => [
            'id' => ['alias' => 'customer_id', 'type' => 'equal'],
            'uuid' => ['alias' => 'customer_uuid', 'type' => 'equal'],
            'name' => ['alias' => 'customer_name', 'type' => 'like'],
            'email' => ['alias' => 'customer_email', 'type' => 'equal'],
            'whatsapp' => ['alias' => 'customer_whatsapp', 'type' => 'equal'],
            'document' => ['alias' => 'customer_document', 'type' => 'equal'],
        ],
        'product' => [
            'uuid' => ['alias' => 'product_uuid', 'type' => 'equal'],
            'name' => ['alias' => 'product_name', 'type' => 'equal'],
            'slug' => ['alias' => 'product_slug', 'type' => 'equal'],
            'reference' => ['alias' => 'product_ref', 'type' => 'equal'],
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const PASSWORD_RANGE_FILTERS = [
        'created_at' => [
            'gte' => 'created_at_gte',
            'lte' => 'created_at_lte',
        ],
        'updated_at' => [
            'gte' => 'updated_at_gte',
            'lte' => 'updated_at_lte',
        ],
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
    /**
     * @var array<string, string>
     */
    private const BLACKLIST_EQUAL_FILTERS = [
        'whatsapp' => 'whatsapp',
        'has_closed_order' => 'has_closed_order',
    ];

    /**
     * @var array<string, string>
     */
    private const BLACKLIST_LIKE_FILTERS = [
        'name' => 'name_like',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const BLACKLIST_RANGE_FILTERS = [
        'created_at' => [
            'gte' => 'created_at_gte',
            'lte' => 'created_at_lte',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const SCHEDULED_POSTS_EQUAL_FILTERS = [
        'type' => 'type',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const SCHEDULED_POSTS_RANGE_FILTERS = [
        'scheduled_datetime' => [
            'gte' => 'scheduled_datetime_gte',
            'lte' => 'scheduled_datetime_lte',
        ],
        'created_at' => [
            'gte' => 'created_at_gte',
            'lte' => 'created_at_lte',
        ],
    ];

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

        $inlineFilters = $this->extractSoldItemsInlineFilters($queryParams);
        if ($inlineFilters !== []) {
            $crmQuery += $this->mapSoldItemsFilters($inlineFilters);
        }

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

    /**
     * @return array<string, mixed>
     */
    private function extractSoldItemsInlineFilters(array $params): array
    {
        $inline = [];

        foreach (array_keys(self::SOLD_ITEMS_EQUAL_FILTERS) as $key) {
            if (array_key_exists($key, $params)) {
                $inline[$key] = $params[$key];
            }
        }

        foreach (array_keys(self::SOLD_ITEMS_RANGE_FILTERS) as $key) {
            if (array_key_exists($key, $params)) {
                $inline[$key] = $params[$key];
            }
        }

        return $inline;
    }

    public function mapBlacklist(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $mappedFilters = $this->mapBlacklistFilters($filters);

        $inlineFilters = $this->extractBlacklistInlineFilters($queryParams);
        if ($inlineFilters !== []) {
            $mappedFilters = array_merge($mappedFilters, $this->mapBlacklistFilters($inlineFilters));
        }

        $crmQuery = [
            'filters' => $mappedFilters,
        ];

        $search = $this->sanitizeScalar($queryParams['q'] ?? null);
        if ($search !== '') {
            $crmQuery['search'] = $search;
        }

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, $fields);
    }

    public function mapScheduledPosts(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $mappedFilters = $this->mapScheduledPostsFilters($filters);

        $inlineFilters = $this->extractScheduledPostsInlineFilters($queryParams);
        if ($inlineFilters !== []) {
            $mappedFilters = array_merge($mappedFilters, $this->mapScheduledPostsFilters($inlineFilters));
        }

        $crmQuery = [
            'filters' => $mappedFilters,
        ];

        $search = $this->sanitizeScalar($queryParams['q'] ?? null);
        if ($search !== '') {
            $crmQuery['search'] = $search;
        }

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, $fields);
    }

    public function mapPasswords(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $mappedFilters = $this->mapPasswordFilters($filters);

        $inlineFilters = $this->extractPasswordInlineFilters($queryParams);
        if ($inlineFilters !== []) {
            $mappedFilters = array_merge($mappedFilters, $this->mapPasswordFilters($inlineFilters));
        }

        $crmQuery = [
            'filters' => $mappedFilters,
        ];

        $search = $this->sanitizeScalar($queryParams['q'] ?? null);
        if ($search !== '') {
            $crmQuery['search'] = $search;
        }

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions($crmQuery, $page, $perPage, $fetchAll, $sort, $fields);
    }

    public function mapSchools(array $queryParams): QueryOptions
    {
        [$page, $perPage] = $this->resolvePagination($queryParams);
        $fetchAll = $this->normalizeFetch($queryParams['fetch'] ?? ($queryParams['all'] ?? null));

        $filters = $this->normalizeArray($queryParams['filter'] ?? []);
        $mappedFilters = $this->mapSchoolFilters($filters);

        $inline = $this->extractSchoolInlineFilters($queryParams);
        if ($inline !== []) {
            $mappedFilters = array_merge($mappedFilters, $this->mapSchoolFilters($inline));
        }

        $crmQuery = [];
        if ($mappedFilters !== []) {
            $crmQuery['filters'] = $mappedFilters;
        }

        $search = $this->sanitizeScalar($queryParams['search'] ?? null);
        if ($search !== '') {
            $crmQuery['search'] = $search;
        }

        $sort = $this->parseSort($queryParams['sort'] ?? null);
        $fields = $this->parseFields($queryParams['fields'] ?? []);

        return new QueryOptions(
            $crmQuery,
            $page,
            $perPage,
            $fetchAll,
            $this->filterSchoolSort($sort),
            $fields
        );
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
        $filters = $this->expandOrderStructuredFilters($filters);

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
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mapScheduledPostsFilters(array $filters): array
    {
        $mapped = [];

        if (isset($filters['type'])) {
            $type = strtolower($this->sanitizeScalar($filters['type']));
            if ($type !== '') {
                $mapped['type'] = $type;
            }
        }

        if (isset($filters['status'])) {
            $status = strtolower($this->sanitizeScalar($filters['status']));
            if (in_array($status, ['pending', 'scheduled', 'sent', 'failed'], true)) {
                $mapped['status'] = $status;
            }
        }

        if (array_key_exists('has_media', $filters)) {
            $flag = $this->normalizeBoolean($filters['has_media']);
            if ($flag !== null) {
                $mapped['has_media'] = $flag;
            }
        }

        if (isset($filters['caption_contains'])) {
            $caption = $this->sanitizeScalar($filters['caption_contains']);
            if ($caption !== '') {
                $mapped['caption_contains'] = $caption;
            }
        }

        if (isset($filters['scheduled_today'])) {
            $flag = $this->normalizeBoolean($filters['scheduled_today']);
            if ($flag === true) {
                $mapped['scheduled_today'] = true;
            }
        }

        if (isset($filters['scheduled_this_week'])) {
            $flag = $this->normalizeBoolean($filters['scheduled_this_week']);
            if ($flag === true) {
                $mapped['scheduled_this_week'] = true;
            }
        }

        foreach (self::SCHEDULED_POSTS_RANGE_FILTERS as $source => $operators) {
            if (isset($filters[$source]) && is_array($filters[$source])) {
                foreach ($operators as $operator => $target) {
                    $value = $this->sanitizeScalar($filters[$source][$operator] ?? null);
                    if ($value !== '') {
                        $mapped[$target] = $value;
                    }
                }
            }

            foreach ($operators as $operator => $target) {
                $direct = $source . '_' . $operator;
                if (isset($filters[$direct])) {
                    $value = $this->sanitizeScalar($filters[$direct]);
                    if ($value !== '') {
                        $mapped[$target] = $value;
                    }
                }
            }
        }

        if (isset($filters['messageId'])) {
            $state = $this->normalizeMessageIdState($filters['messageId']);
            if ($state !== null) {
                $mapped['message_id_state'] = $state;
            }
        }

        if (isset($filters['message_id_state'])) {
            $state = $this->normalizeMessageIdState($filters['message_id_state']);
            if ($state !== null) {
                $mapped['message_id_state'] = $state;
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractScheduledPostsInlineFilters(array $params): array
    {
        $inline = [];

        if (array_key_exists('type', $params) && !is_array($params['type'])) {
            $inline['type'] = $params['type'];
        }

        if (isset($params['scheduled_datetime']) && is_array($params['scheduled_datetime'])) {
            $inline['scheduled_datetime'] = $params['scheduled_datetime'];
        }

        foreach (['scheduled_datetime_gte' => 'gte', 'scheduled_datetime_lte' => 'lte'] as $key => $operator) {
            if (array_key_exists($key, $params)) {
                if (!isset($inline['scheduled_datetime']) || !is_array($inline['scheduled_datetime'])) {
                    $inline['scheduled_datetime'] = [];
                }
                $inline['scheduled_datetime'][$operator] = $params[$key];
            }
        }

        if (isset($params['created_at']) && is_array($params['created_at'])) {
            $inline['created_at'] = $params['created_at'];
        }

        foreach (['created_at_gte' => 'gte', 'created_at_lte' => 'lte'] as $key => $operator) {
            if (array_key_exists($key, $params)) {
                if (!isset($inline['created_at']) || !is_array($inline['created_at'])) {
                    $inline['created_at'] = [];
                }
                $inline['created_at'][$operator] = $params[$key];
            }
        }

        foreach (['status', 'has_media', 'caption_contains', 'scheduled_today', 'scheduled_this_week'] as $scalarKey) {
            if (array_key_exists($scalarKey, $params) && !is_array($params[$scalarKey])) {
                $inline[$scalarKey] = $params[$scalarKey];
            }
        }

        if (array_key_exists('messageId', $params) && !is_array($params['messageId'])) {
            $inline['messageId'] = $params['messageId'];
        }

        if (array_key_exists('message_id_state', $params) && !is_array($params['message_id_state'])) {
            $inline['message_id_state'] = $params['message_id_state'];
        }

        return $inline;
    }

    private function normalizeMessageIdState(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'null', 'none', '' => 'null',
            '!null', 'not_null' => 'not_null',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mapPasswordFilters(array $filters): array
    {
        $mapped = [];

        if (isset($filters['tipo'])) {
            $tipo = $this->sanitizeScalar($filters['tipo']);
            if ($tipo !== '') {
                $mapped['tipo'] = $tipo;
            }
        }

        if (isset($filters['local'])) {
            $local = $this->sanitizeScalar($filters['local']);
            if ($local !== '') {
                $mapped['local'] = $local;
            }
        }

        if (array_key_exists('verificado', $filters)) {
            $flag = $this->normalizeBoolean($filters['verificado']);
            if ($flag !== null) {
                $mapped['verificado'] = $flag;
            }
        }

        if (array_key_exists('ativo', $filters)) {
            $flag = $this->normalizeBoolean($filters['ativo']);
            if ($flag !== null) {
                $mapped['ativo'] = $flag;
            }
        }

        if (array_key_exists('include_inactive', $filters)) {
            $flag = $this->normalizeBoolean($filters['include_inactive']);
            if ($flag !== null) {
                $mapped['include_inactive'] = $flag;
            }
        }

        foreach (self::PASSWORD_RANGE_FILTERS as $source => $operators) {
            if (isset($filters[$source]) && is_array($filters[$source])) {
                foreach ($operators as $operator => $target) {
                    $value = $this->sanitizeScalar($filters[$source][$operator] ?? null);
                    if ($value !== '') {
                        $mapped[$target] = $value;
                    }
                }
            }

            foreach ($operators as $operator => $target) {
                $direct = sprintf('%s_%s', $source, $operator);
                if (!array_key_exists($direct, $filters)) {
                    continue;
                }

                $value = $this->sanitizeScalar($filters[$direct]);
                if ($value !== '') {
                    $mapped[$target] = $value;
                }
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPasswordInlineFilters(array $params): array
    {
        $inline = [];

        foreach (['tipo', 'local'] as $scalar) {
            if (array_key_exists($scalar, $params) && !is_array($params[$scalar])) {
                $inline[$scalar] = $params[$scalar];
            }
        }

        foreach (['verificado', 'ativo', 'include_inactive'] as $booleanKey) {
            if (array_key_exists($booleanKey, $params) && !is_array($params[$booleanKey])) {
                $inline[$booleanKey] = $params[$booleanKey];
            }
        }

        foreach (self::PASSWORD_RANGE_FILTERS as $field => $operators) {
            if (isset($params[$field]) && is_array($params[$field])) {
                $inline[$field] = $params[$field];
            }

            foreach (array_keys($operators) as $operator) {
                $key = sprintf('%s_%s', $field, $operator);
                if (!array_key_exists($key, $params)) {
                    continue;
                }

                if (!isset($inline[$field]) || !is_array($inline[$field])) {
                    $inline[$field] = [];
                }

                $inline[$field][$operator] = $params[$key];
            }
        }

        return $inline;
    }

    private function mapBlacklistFilters(array $filters): array
    {
        $mapped = [];

        if (array_key_exists('whatsapp', $filters)) {
            $whatsapp = $filters['whatsapp'];
            if (is_array($whatsapp)) {
                $candidate = $whatsapp['eq'] ?? null;
            } else {
                $candidate = $whatsapp;
            }

            $normalized = $this->sanitizeWhatsapp($candidate);
            if ($normalized !== '') {
                $mapped['whatsapp'] = $normalized;
            }
        }

        if (array_key_exists('name', $filters)) {
            $nameValue = $filters['name'];
            if (is_array($nameValue)) {
                $candidate = $nameValue['like'] ?? ($nameValue['eq'] ?? null);
            } else {
                $candidate = $nameValue;
            }

            $normalized = $this->sanitizeScalar($candidate);
            if ($normalized !== '') {
                $mapped['name_like'] = $normalized;
            }
        }

        if (array_key_exists('name_like', $filters)) {
            $normalized = $this->sanitizeScalar($filters['name_like']);
            if ($normalized !== '') {
                $mapped['name_like'] = $normalized;
            }
        }

        if (array_key_exists('has_closed_order', $filters)) {
            $value = $filters['has_closed_order'];
            if (is_array($value)) {
                $candidate = $value['eq'] ?? null;
            } else {
                $candidate = $value;
            }

            $boolean = $this->normalizeBoolean($candidate);
            if ($boolean !== null) {
                $mapped['has_closed_order'] = $boolean;
            }
        }

        foreach (self::BLACKLIST_RANGE_FILTERS as $source => $operators) {
            if (isset($filters[$source]) && is_array($filters[$source])) {
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

            foreach ($operators as $operator => $target) {
                $directKey = $source . '_' . $operator;
                if (!array_key_exists($directKey, $filters)) {
                    continue;
                }

                $normalized = $this->sanitizeScalar($filters[$directKey]);
                if ($normalized !== '') {
                    $mapped[$target] = $normalized;
                }
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractBlacklistInlineFilters(array $params): array
    {
        $inline = [];

        foreach (array_keys(self::BLACKLIST_EQUAL_FILTERS) as $key) {
            if (array_key_exists($key, $params) && !is_array($params[$key])) {
                $inline[$key] = $params[$key];
            }
        }

        if (array_key_exists('name', $params) && !is_array($params['name'])) {
            $inline['name'] = ['like' => $params['name']];
        }

        if (isset($params['created_at']) && is_array($params['created_at'])) {
            $inline['created_at'] = $params['created_at'];
        }

        foreach (['created_at_gte' => 'gte', 'created_at_lte' => 'lte'] as $key => $operator) {
            if (array_key_exists($key, $params)) {
                if (!isset($inline['created_at'])) {
                    $inline['created_at'] = [];
                }
                $inline['created_at'][$operator] = $params[$key];
            }
        }

        return $inline;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'sim'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'nao'], true)) {
                return false;
            }
        }

        return null;
    }

    private function sanitizeWhatsapp(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits ?? '';
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
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function expandOrderStructuredFilters(array $filters): array
    {
        $expanded = [];

        foreach (self::ORDER_STRUCTURED_MAPPING as $namespace => $fieldMap) {
            if (!isset($filters[$namespace]) || !is_array($filters[$namespace])) {
                continue;
            }

            foreach ($fieldMap as $field => $definition) {
                if (!array_key_exists($field, $filters[$namespace])) {
                    continue;
                }

                $alias = $definition['alias'];
                $type = $definition['type'];
                $value = $filters[$namespace][$field];

                if ($type === 'equal') {
                    if (!array_key_exists($alias, $filters) && !array_key_exists($alias, $expanded)) {
                        $expanded[$alias] = $value;
                    }
                    continue;
                }

                if ($type === 'like') {
                    if (array_key_exists($alias, $filters) || array_key_exists($alias, $expanded)) {
                        continue;
                    }

                    if (is_array($value)) {
                        $candidate = $value['like'] ?? $value['eq'] ?? null;
                    } else {
                        $candidate = $value;
                    }

                    if ($candidate !== null) {
                        $expanded[$alias] = ['like' => $candidate];
                    }
                    continue;
                }

                if ($type === 'range') {
                    $operator = $definition['operator'];
                    $existing = [];

                    if (isset($filters[$alias]) && is_array($filters[$alias])) {
                        $existing = $filters[$alias];
                    }

                    if (isset($expanded[$alias]) && is_array($expanded[$alias])) {
                        $existing = array_merge($existing, $expanded[$alias]);
                    }

                    if (is_array($value)) {
                        if (isset($value[$operator])) {
                            $existing[$operator] = $value[$operator];
                        }
                    } else {
                        $existing[$operator] = $value;
                    }

                    if ($existing !== []) {
                        $expanded[$alias] = $existing;
                    }
                }
            }
        }

        if ($expanded === []) {
            return $filters;
        }

        return array_merge($filters, $expanded);
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
        if (is_array($sort)) {
            $field = $this->sanitizeScalar($sort['field'] ?? null);
            if ($field === '') {
                return [];
            }

            $direction = strtolower($this->sanitizeScalar($sort['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            return [
                [
                    'field' => $field,
                    'direction' => $direction,
                ],
            ];
        }

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
     * @param array<int, array{field: string, direction: string}> $sort
     * @return array<int, array{field: string, direction: string}>
     */
    private function filterSchoolSort(array $sort): array
    {
        if ($sort === []) {
            return [];
        }

        $allowed = ['nome', 'total_alunos', 'panfletagem', 'cidade'];

        return array_values(array_filter(
            $sort,
            static fn (array $rule): bool => in_array($rule['field'], $allowed, true)
        ));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mapSchoolFilters(array $filters): array
    {
        $mapped = [];

        $cidade = $this->normalizeIntList($filters['cidade_id'] ?? null);
        if ($cidade !== []) {
            $mapped['cidade_id'] = $cidade;
        }

        $bairro = $this->normalizeIntList($filters['bairro_id'] ?? null);
        if ($bairro !== []) {
            $mapped['bairro_id'] = $bairro;
        }

        $status = $this->sanitizeScalar($filters['status'] ?? null);
        if (in_array($status, ['pendente', 'feito', 'todos'], true)) {
            $mapped['status'] = $status;
        }

        $tipos = $this->normalizeStringList($filters['tipo'] ?? null);
        if ($tipos !== []) {
            $mapped['tipo'] = $tipos;
        }

        $periodos = $this->normalizeStringList($filters['periodos'] ?? null);
        if ($periodos !== []) {
            $mapped['periodos'] = $periodos;
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function extractSchoolInlineFilters(array $queryParams): array
    {
        $inline = [];

        foreach (['cidade_id', 'bairro_id', 'status', 'tipo', 'periodos'] as $key) {
            if (!array_key_exists($key, $queryParams)) {
                continue;
            }

            $inline[$key] = $queryParams[$key];
        }

        return $inline;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeIntList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $list = array_map(static fn ($item): int => (int) $item, $value);
        } else {
            $list = [(int) $value];
        }

        $list = array_filter($list, static fn (int $item): bool => $item > 0);

        return array_values(array_unique($list));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $list = array_map(static fn ($item): string => trim((string) $item), $value);
        } else {
            $list = [trim((string) $value)];
        }

        $list = array_filter($list, static fn (string $item): bool => $item !== '');

        return array_values(array_unique($list));
    }
}



