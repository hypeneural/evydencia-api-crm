<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Infrastructure\Http\EvydenciaApiClient;
use InvalidArgumentException;
use PDO;
use PDOException;
use Predis\Client as PredisClient;
use Predis\Exception\PredisException;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class BaseReport implements ReportInterface
{
    protected ReportHelpers $helpers;

    protected EvydenciaApiClient $apiClient;

    public function __construct(
        protected readonly EvydenciaApiClient $crm,
        protected readonly ?PDO $pdo,
        protected readonly ?PredisClient $redis,
        protected readonly LoggerInterface $logger
    ) {
        $this->helpers = new ReportHelpers();
        // backward compatibility for legacy reports still referencing $this->apiClient
        $this->apiClient = $crm;
    }

    /**
     * @return array<int, mixed>
     */
    protected function fromArray(iterable $items): array
    {
        return $this->fromIterable($items);
    }

    /**
     * @return array<int, mixed>
     */
    protected function fromIterable(iterable $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        return iterator_to_array($items, false);
    }

    protected function crm(): EvydenciaApiClient
    {
        return $this->crm;
    }

    protected function pdo(): ?PDO
    {
        return $this->pdo;
    }

    protected function redis(): ?PredisClient
    {
        return $this->redis;
    }

    protected function logger(): LoggerInterface
    {
        return $this->logger;
    }

    protected function helpers(): ReportHelpers
    {
        return $this->helpers;
    }

    protected function pipeline(iterable $data): ReportPipeline
    {
        return new ReportPipeline($this->fromIterable($data));
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, mixed>
     */
    protected function fetchCrm(string $endpoint, array $query, string $traceId, int $maxPages = 10): array
    {
        $page = 1;
        $results = [];
        $currentQuery = $query;

        while ($page <= $maxPages) {
            $response = $this->crm->get($endpoint, $currentQuery, $traceId);
            $body = $response['body'] ?? [];
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
            $results = array_merge($results, $data);

            $links = $body['links'] ?? [];
            $next = is_array($links) ? ($links['next'] ?? null) : null;
            if (!is_string($next) || $next === '') {
                break;
            }

            $nextQuery = $this->extractQueryFromLink($next);
            if ($nextQuery === null) {
                break;
            }

            $currentQuery = array_merge($currentQuery, $nextQuery);
            $page++;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, mixed>
     */
    protected function fetchOrders(array $query, string $traceId, int $maxPages = 10): array
    {
        $page = 1;
        $results = [];
        $currentQuery = $query;

        while ($page <= $maxPages) {
            $response = $this->crm->searchOrders($currentQuery, $traceId);
            $body = $response['body'] ?? [];
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
            $results = array_merge($results, $data);

            $links = $body['links'] ?? [];
            $next = is_array($links) ? ($links['next'] ?? null) : null;
            if (!is_string($next) || $next === '') {
                break;
            }

            $nextQuery = $this->extractQueryFromLink($next);
            if ($nextQuery === null) {
                break;
            }

            $currentQuery = array_merge($currentQuery, $nextQuery);
            $page++;
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchMysql(string $sql, array $params = []): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('MySQL connection is not configured.');
        }

        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $statement->bindValue(is_int($key) ? $key + 1 : $key, $value);
            }
            $statement->execute();

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to execute MySQL query: ' . $exception->getMessage(), 0, $exception);
        }
    }

    protected function remember(string $cacheKey, int $ttl, callable $callback): ReportResult
    {
        if ($ttl <= 0 || $this->redis === null) {
            return $callback();
        }

        try {
            $cached = $this->redis->get($cacheKey);
        } catch (PredisException) {
            $cached = null;
        }

        if (is_string($cached) && $cached !== '') {
            $payload = json_decode($cached, true);
            if (is_array($payload) && isset($payload['data'], $payload['summary'], $payload['meta'], $payload['columns'])) {
                $meta = is_array($payload['meta']) ? $payload['meta'] : [];
                $meta['cache'] = ($meta['cache'] ?? []) + ['hit' => true, 'key' => $cacheKey];

                return new ReportResult(
                    $payload['data'],
                    $payload['summary'],
                    $meta,
                    $payload['columns']
                );
            }
        }

        $result = $callback();
        $meta = $result->meta;
        $meta['cache'] = ($meta['cache'] ?? []) + ['hit' => false, 'key' => $cacheKey];
        $result->meta = $meta;

        $payload = [
            'data' => $result->data,
            'summary' => $result->summary,
            'meta' => $result->meta,
            'columns' => $result->columns,
        ];

        try {
            $this->redis->setex($cacheKey, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (PredisException) {
            // ignore cache errors
        }

        return $result;
    }

    protected function makeCacheKey(string $reportKey, array $query): string
    {
        ksort($query);

        return sprintf('evyapi:report:%s:%s', $reportKey, sha1((string) json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function ruleManager(array $record = []): RuleManager
    {
        return new RuleManager();
    }

    /**
     * @return array<string, mixed>|null
     */
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
}

final class ReportPipeline
{
    /**
     * @var array<int, mixed>
     */
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function filter(callable $callback): self
    {
        $this->data = array_values(array_filter($this->data, $callback));

        return $this;
    }

    public function map(callable $callback): self
    {
        $this->data = array_values(array_map($callback, $this->data));

        return $this;
    }

    public function compute(callable $callback): self
    {
        return $this->map($callback);
    }

    /**
     * @param callable|string|array<int, string> $keyResolver
     * @param callable|array<string, callable>|null $aggregator
     */
    public function groupBy(callable|string|array $keyResolver, callable|array|null $aggregator = null): self
    {
        $resolver = $this->createGroupKeyResolver($keyResolver);
        $grouped = [];

        foreach ($this->data as $item) {
            [$hash, $keyPayload] = $resolver($item);
            if (!isset($grouped[$hash])) {
                $grouped[$hash] = [
                    'key' => $keyPayload,
                    'items' => [],
                ];
            }

            $grouped[$hash]['items'][] = $item;
        }

        if ($aggregator === null) {
            $this->data = array_values($grouped);

            return $this;
        }

        if (is_callable($aggregator)) {
            $this->data = array_values(array_map(
                static fn (array $group) => $aggregator($group['key'], $group['items']),
                $grouped
            ));

            return $this;
        }

        if (is_array($aggregator)) {
            $this->data = array_values(array_map(function (array $group) use ($aggregator) {
                $row = [];
                $keyPayload = $group['key'];

                if (is_array($keyPayload)) {
                    foreach ($keyPayload as $field => $value) {
                        $row[$field] = $value;
                    }
                } else {
                    $row['key'] = $keyPayload;
                }

                foreach ($aggregator as $field => $callback) {
                    if (!is_callable($callback)) {
                        throw new InvalidArgumentException('Aggregator for field ' . $field . ' must be callable.');
                    }

                    $row[$field] = $callback($group['items']);
                }

                return $row;
            }, $grouped));

            return $this;
        }

        throw new InvalidArgumentException('Invalid aggregator provided for groupBy.');
    }

    public function aggregate(array $aggregators): self
    {
        $result = [];

        foreach ($aggregators as $field => $callback) {
            if (!is_callable($callback)) {
                throw new InvalidArgumentException('Aggregator for field ' . $field . ' must be callable.');
            }

            $result[$field] = $callback($this->data);
        }

        $this->data = [$result];

        return $this;
    }

    public function sort(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        usort($this->data, static function ($left, $right) use ($field, $direction): int {
            $l = is_array($left) ? ($left[$field] ?? null) : null;
            $r = is_array($right) ? ($right[$field] ?? null) : null;

            if ($l === $r) {
                return 0;
            }

            if ($l === null) {
                return $direction === 'asc' ? -1 : 1;
            }

            if ($r === null) {
                return $direction === 'asc' ? 1 : -1;
            }

            if (is_numeric($l) && is_numeric($r)) {
                return $direction === 'asc' ? ($l <=> $r) : ($r <=> $l);
            }

            return $direction === 'asc'
                ? strnatcasecmp((string) $l, (string) $r)
                : strnatcasecmp((string) $r, (string) $l);
        });

        return $this;
    }

    public function paginate(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $offset = ($page - 1) * $perPage;

        return array_slice($this->data, $offset, $perPage);
    }

    /**
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private function createGroupKeyResolver(callable|string|array $keyResolver): callable
    {
        if (is_callable($keyResolver)) {
            return function ($item) use ($keyResolver): array {
                $resolved = $keyResolver($item);
                $normalized = $this->normalizeGroupKey($resolved);

                return [$this->hashGroupKey($normalized), $normalized];
            };
        }

        $fields = is_array($keyResolver) ? array_values($keyResolver) : [$keyResolver];

        return function ($item) use ($fields): array {
            $normalized = [];
            foreach ($fields as $field) {
                $value = null;
                if (is_array($item) && array_key_exists($field, $item)) {
                    $value = $item[$field];
                }
                $normalized[$field] = $value;
            }

            return [$this->hashGroupKey($normalized), $normalized];
        };
    }

    private function normalizeGroupKey(mixed $key): mixed
    {
        if (is_array($key)) {
            return $key;
        }

        return ['key' => $key];
    }

    private function hashGroupKey(mixed $key): string
    {
        return sha1((string) json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
