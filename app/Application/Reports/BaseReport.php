<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Support\QueryMapper;
use App\Infrastructure\Http\EvydenciaApiClient;
use Predis\Client as PredisClient;
use Predis\Exception\PredisException;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validatable;

abstract class BaseReport implements ReportInterface
{
    protected const DEFAULT_PAGE = 1;
    protected const DEFAULT_PER_PAGE = 50;
    protected const MAX_PER_PAGE = 100;
    protected const CACHE_PREFIX = 'evyapi:report:';

    /**
     * @var array<string>
     */
    private const ALLOWED_FILTER_KEYS = [
        'order[uuid]',
        'order[status]',
        'order[created-start]',
        'order[created-end]',
        'order[session-start]',
        'order[session-end]',
        'order[selection-start]',
        'order[selection-end]',
        'customer[id]',
        'customer[uuid]',
        'customer[name]',
        'customer[email]',
        'customer[whatsapp]',
        'customer[document]',
        'product[uuid]',
        'product[name]',
        'product[slug]',
        'product[reference]',
        'include',
        'fields',
        'page',
        'per_page',
        'sort',
        'dir',
    ];

    public function __construct(
        protected readonly EvydenciaApiClient $apiClient,
        protected readonly QueryMapper $queryMapper,
        protected readonly ?PredisClient $redis,
        protected readonly LoggerInterface $logger
    ) {
    }

    final public function cacheKey(array $filters): string
    {
        ksort($filters);
        return self::CACHE_PREFIX . $this->key() . ':' . sha1((string) json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    protected function normalizeFilters(array $input, array $defaults = []): array
    {
        $normalized = $defaults;

        foreach ($input as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!in_array($key, self::ALLOWED_FILTER_KEYS, true) && !array_key_exists($key, $defaults)) {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = array_filter($value, static fn ($item): bool => $item !== null && $item !== '');
                continue;
            }

            if ($value === null) {
                continue;
            }

            $scalarValue = is_scalar($value) ? trim((string) $value) : '';
            if ($scalarValue === '') {
                continue;
            }

            $normalized[$key] = $scalarValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{page: int, per_page: int}
     */
    protected function resolvePagination(array $filters): array
    {
        $page = (int) ($filters['page'] ?? self::DEFAULT_PAGE);
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        if ($page < 1) {
            $page = self::DEFAULT_PAGE;
        }

        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        return ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, mixed>>
     */
    protected function sortData(array $data, ?string $field, string $direction = 'asc'): array
    {
        if ($field === null || $field === '' || $data === []) {
            return $data;
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        usort($data, function (array $left, array $right) use ($field, $direction): int {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;

            if ($leftValue === $rightValue) {
                return 0;
            }

            if ($leftValue === null) {
                return $direction === 'asc' ? -1 : 1;
            }

            if ($rightValue === null) {
                return $direction === 'asc' ? 1 : -1;
            }

            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                return $direction === 'asc' ? ($leftValue <=> $rightValue) : ($rightValue <=> $leftValue);
            }

            $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);

            return $direction === 'asc' ? $comparison : -$comparison;
        });

        return $data;
    }

    /**
     * @template T of array<string, mixed>
     * @param array<int, T> $items
     * @param callable(T): string|null $packageResolver
     * @param callable(T): array<string, float|int> $metricsResolver
     * @return array<string, array<string, float>>
     */
    protected function aggregateByPackage(array $items, callable $packageResolver, callable $metricsResolver): array
    {
        $aggregated = [];

        foreach ($items as $item) {
            $package = $packageResolver($item);
            if ($package === null || $package === '') {
                $package = 'unknown';
            }

            $metrics = $metricsResolver($item);
            if (!is_array($metrics)) {
                continue;
            }

            if (!isset($aggregated[$package])) {
                $aggregated[$package] = [];
            }

            foreach ($metrics as $name => $value) {
                $numeric = is_numeric($value) ? (float) $value : 0.0;
                $aggregated[$package][$name] = ($aggregated[$package][$name] ?? 0.0) + $numeric;
            }
        }

        return $aggregated;
    }

    /**
     * @param array<string, array<string, float>> $current
     * @param array<string, array<string, float>> $previous
     * @param string $metric
     * @return array<string, array<string, float>>
     */
    protected function compareTwoPeriodsByPackage(array $current, array $previous, string $metric): array
    {
        $result = [];
        $packages = array_unique(array_merge(array_keys($current), array_keys($previous)));

        foreach ($packages as $package) {
            $currentValue = $current[$package][$metric] ?? 0.0;
            $previousValue = $previous[$package][$metric] ?? 0.0;
            $difference = $currentValue - $previousValue;
            $result[$package] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'delta' => $difference,
                'delta_percent' => $this->percent($difference, $previousValue),
            ];
        }

        return $result;
    }

    /**
     * @template T of array<string, mixed>
     * @param array<int, T> $items
     * @return array<string, T>
     */
    protected function indexBy(array $items, string $field): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (!isset($item[$field])) {
                continue;
            }
            $key = (string) $item[$field];
            if ($key === '') {
                continue;
            }
            $indexed[$key] = $item;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function sumBy(array $items, string $field): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            if (!isset($item[$field])) {
                continue;
            }
            $value = $item[$field];
            if (is_numeric($value)) {
                $sum += (float) $value;
            }
        }

        return $sum;
    }

    protected function percent(float $part, float $total): float
    {
        if ($total == 0.0) {
            return 0.0;
        }

        return ($part / $total) * 100.0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    protected function mergeIncludes(array $filters, array $requiredIncludes): array
    {
        if ($requiredIncludes === []) {
            return $filters;
        }

        $existing = isset($filters['include']) ? $filters['include'] : null;
        $normalized = [];

        if (is_string($existing) && $existing !== '') {
            $normalized = array_filter(array_map('trim', explode(',', $existing)));
        } elseif (is_array($existing)) {
            $normalized = array_filter(array_map(static fn ($value): string => is_scalar($value) ? trim((string) $value) : '', $existing));
        }

        $normalized = array_values(array_unique(array_merge($normalized, $requiredIncludes)));
        $filters['include'] = implode(',', $normalized);

        return $filters;
    }

    /**
     * @param callable(): ReportResult $callback
     */
    protected function remember(array $filters, int $ttl, callable $callback): ReportResult
    {
        $cacheDisabled = false;
        if (isset($filters['_cache_disabled'])) {
            $cacheDisabled = (bool) $filters['_cache_disabled'];
            unset($filters['_cache_disabled']);
        }

        if (isset($filters['_cache_ttl_override']) && is_numeric($filters['_cache_ttl_override'])) {
            $ttl = max(0, (int) $filters['_cache_ttl_override']);
            unset($filters['_cache_ttl_override']);
        }

        if ($cacheDisabled || $ttl <= 0 || $this->redis === null) {
            return $callback();
        }

        $cacheKey = $this->cacheKey($filters);

        try {
            $cached = $this->redis->get($cacheKey);
        } catch (PredisException) {
            $cached = null;
        }

        if (is_string($cached) && $cached !== '') {
            $payload = json_decode($cached, true);
            if (is_array($payload) && isset($payload['data'], $payload['summary'], $payload['meta'], $payload['columns'])) {
                $meta = is_array($payload['meta']) ? $payload['meta'] : [];
                $meta['cache_hit'] = true;

                return new ReportResult(
                    $payload['data'],
                    $payload['summary'],
                    $meta,
                    $payload['columns']
                );
            }
        }

        $result = $callback();

        $payload = [
            'data' => $result->data,
            'summary' => $result->summary,
            'meta' => $result->meta,
            'columns' => $result->columns,
        ];

        try {
            $this->redis->setex($cacheKey, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (PredisException) {
            // ignore cache write failures
        }

        return $result;
    }

    protected function extractQueryFromLink(string $link): ?array
    {
        $components = parse_url($link);
        if ($components === false) {
            return null;
        }

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        return $query === [] ? null : $query;
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function extractTraceId(array &$filters): string
    {
        if (isset($filters['_trace_id']) && is_string($filters['_trace_id']) && $filters['_trace_id'] !== '') {
            $traceId = $filters['_trace_id'];
            unset($filters['_trace_id']);

            return $traceId;
        }

        return bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, Validatable> $rules
     * @param array<string, mixed> $filters
     * @return array<int, array<string, string>>
     */
    protected function validateFilters(array $rules, array $filters): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $filters[$field] ?? null;
            try {
                $rule->assert($value);
            } catch (\Respect\Validation\Exceptions\NestedValidationException $exception) {
                $errors[] = [
                    'field' => $field,
                    'message' => $exception->getMessages()[0] ?? 'Invalid value.',
                ];
            }
        }

        return $errors;
    }
}









