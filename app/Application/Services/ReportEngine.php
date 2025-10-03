<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Reports\BaseReport;
use App\Application\Reports\ReportInterface;
use App\Application\Reports\ReportResult;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Http\EvydenciaApiClient;
use Closure;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use Predis\Client as PredisClient;
use Predis\Exception\PredisException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validatable;
use RuntimeException;

final class ReportEngine
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    /**
     * @var array<string, ReportInterface>
     */
    private array $instances = [];

    public function __construct(
        private readonly EvydenciaApiClient $apiClient,
        private readonly QueryMapper $queryMapper,
        private readonly ?PredisClient $redis,
        private readonly LoggerInterface $logger,
        private readonly string $definitionsPath
    ) {
        $this->definitions = $this->loadDefinitions($definitionsPath);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $items = [];

        foreach ($this->definitions as $key => $definition) {
            $report = ($definition['type'] ?? null) === 'class'
                ? $this->resolveReport($key)
                : null;

            $title = $definition['title'] ?? ($report?->title() ?? $key);
            $columns = $this->resolveColumns($definition, $report);
            $sortable = $this->resolveSortable($definition, $report);

            $items[] = [
                'key' => $key,
                'title' => $title,
                'columns' => $columns,
                'sortable' => $sortable,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function run(string $key, array $query, string $traceId): ReportResult
    {
        $definition = $this->getDefinition($key);
        $report = ($definition['type'] ?? null) === 'class'
            ? $this->resolveReport($key)
            : null;

        $rules = $this->resolveRules($definition, $report);
        $defaults = $this->resolveDefaults($definition, $report);
        $columns = $this->resolveColumns($definition, $report);
        $sortable = $this->resolveSortable($definition, $report);

        $errors = $this->validateQuery($query, $rules);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $page = $this->normalizePage($query['page'] ?? null);
        $perPage = $this->normalizePerPage($query['per_page'] ?? null);
        $sort = $this->normalizeSort($query['sort'] ?? null, $query['dir'] ?? null, $sortable);
        $cacheEnabled = $this->isCacheEnabled($query);
        $ttlOverride = $this->parseCacheTtlOverride($query['cache_ttl'] ?? null);

        $filters = $this->applyDefaults($defaults, $query);
        $filters['page'] = $page;
        $filters['per_page'] = $perPage;
        if ($sort['field'] !== null) {
            $filters['sort'] = $sort['field'];
            $filters['dir'] = $sort['direction'];
        }

        $startedAt = microtime(true);
        $this->logger->info('report_engine.run.start', [
            'key' => $key,
            'trace_id' => $traceId,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort['field'],
            'dir' => $sort['direction'],
            'cache_enabled' => $cacheEnabled,
        ]);

        $cacheHit = false;
        if ($report instanceof BaseReport) {
            $filters['_trace_id'] = $traceId;
            if (!$cacheEnabled) {
                $filters['_cache_disabled'] = true;
            } elseif ($ttlOverride !== null) {
                $filters['_cache_ttl_override'] = $ttlOverride;
            }

            $result = $report->run($filters);
            $cacheHit = (bool) ($result->meta['cache_hit'] ?? false);
        } else {
            $result = $this->runClosureReport(
                $key,
                $definition,
                $filters,
                $traceId,
                $columns,
                $cacheEnabled,
                $ttlOverride,
                $cacheHit
            );
        }

        if ($result->columns === []) {
            $result->columns = $columns;
        }

        $result->meta['page'] = $result->meta['page'] ?? $page;
        $result->meta['per_page'] = $result->meta['per_page'] ?? $perPage;
        $result->meta['count'] = $result->meta['count'] ?? count($result->data);
        $result->meta['total'] = $result->meta['total'] ?? $result->meta['count'];
        $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? $cacheHit;
        $result->meta['source'] = $result->meta['source'] ?? 'crm';
        if (!isset($result->meta['sort']) && $sort['field'] !== null) {
            $result->meta['sort'] = $sort;
        }

        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);
        $result->meta['took_ms'] = $result->meta['took_ms'] ?? $tookMs;

        $this->logger->info('report_engine.run.finish', [
            'key' => $key,
            'trace_id' => $traceId,
            'took_ms' => $tookMs,
            'cache_hit' => $result->meta['cache_hit'],
            'total' => $result->meta['total'],
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function export(string $key, array $query, string $format, string $traceId): StreamInterface
    {
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new ValidationException([[ 'field' => 'format', 'message' => 'Formato invalido. Use csv ou json.' ]]);
        }

        if (!isset($query['per_page'])) {
            $query['per_page'] = 100;
        }

        $result = $this->run($key, $query, $traceId);

        if ($format === 'json') {
            $encoded = json_encode($result->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Falha ao gerar JSON do relatorio.');
            }

            return Utils::streamFor($encoded);
        }

        $columns = $result->columns !== [] ? $result->columns : $this->inferColumns($result->data);

        return $this->createCsvStream($columns, $result->data);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadDefinitions(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new RuntimeException('Arquivo de definicoes de relatorio invalido.');
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefinition(string $key): array
    {
        if (!isset($this->definitions[$key])) {
            throw new ValidationException([[ 'field' => 'key', 'message' => 'Relatorio nao encontrado.' ]]);
        }

        return $this->definitions[$key];
    }

    private function resolveReport(string $key): ReportInterface
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $definition = $this->getDefinition($key);
        $className = $definition['class'] ?? null;

        if (!is_string($className) || !class_exists($className)) {
            throw new RuntimeException(sprintf('Classe de relatorio invalida para %s.', $key));
        }

        $instance = new $className($this->apiClient, $this->queryMapper, $this->redis, $this->logger);
        if (!$instance instanceof ReportInterface) {
            throw new RuntimeException(sprintf('Relatorio %s deve implementar ReportInterface.', $key));
        }

        return $this->instances[$key] = $instance;
    }

    /**
     * @return array<string, Validatable>
     */
    private function resolveRules(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->rules();
        }

        $rules = $definition['rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaults(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->defaultFilters();
        }

        $defaults = $definition['defaults'] ?? [];

        return is_array($defaults) ? $defaults : [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveColumns(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->columns();
        }

        $columns = $definition['columns'] ?? [];

        return is_array($columns) ? array_values($columns) : [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveSortable(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->sortable();
        }

        $sortable = $definition['sortable'] ?? [];

        return is_array($sortable) ? array_values($sortable) : [];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, Validatable> $rules
     * @return array<int, array<string, string>>
     */
    private function validateQuery(array $query, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            if (!$rule instanceof Validatable) {
                continue;
            }

            $value = $query[$field] ?? null;
            try {
                $rule->assert($value);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => $field,
                    'message' => $exception->getMessages()[0] ?? 'Valor invalido.',
                ];
            }
        }

        if (isset($query['page']) && (!is_numeric($query['page']) || (int) $query['page'] < 1)) {
            $errors[] = ['field' => 'page', 'message' => 'page deve ser >= 1'];
        }

        if (isset($query['per_page']) && (!is_numeric($query['per_page']) || (int) $query['per_page'] < 1 || (int) $query['per_page'] > self::MAX_PER_PAGE)) {
            $errors[] = ['field' => 'per_page', 'message' => 'per_page deve estar entre 1 e 100'];
        }

        if (isset($query['dir']) && !in_array(strtolower((string) $query['dir']), ['asc', 'desc'], true)) {
            $errors[] = ['field' => 'dir', 'message' => 'dir deve ser asc ou desc'];
        }

        if (isset($query['cache']) && !in_array(strtolower((string) $query['cache']), ['0', '1', 'true', 'false'], true)) {
            $errors[] = ['field' => 'cache', 'message' => 'cache deve ser 0 ou 1'];
        }

        if (isset($query['cache_ttl']) && !is_numeric($query['cache_ttl'])) {
            $errors[] = ['field' => 'cache_ttl', 'message' => 'cache_ttl deve ser numerico'];
        }

        return $errors;
    }

    private function normalizePage(mixed $value): int
    {
        $page = (int) ($value ?? self::DEFAULT_PAGE);

        return max(1, $page);
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = (int) ($value ?? self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        return min(self::MAX_PER_PAGE, $perPage);
    }

    /**
     * @param array<int, string> $sortable
     * @return array{field: string|null, direction: string}
     */
    private function normalizeSort(mixed $field, mixed $direction, array $sortable): array
    {
        if (!is_string($field) || $field === '' || !in_array($field, $sortable, true)) {
            return ['field' => null, 'direction' => 'asc'];
        }

        $dir = is_string($direction) && strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return ['field' => $field, 'direction' => $dir];
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function applyDefaults(array $defaults, array $query): array
    {
        $filters = $defaults;

        foreach ($query as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'sort', 'dir', 'cache', 'cache_ttl'], true)) {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    private function isCacheEnabled(array $query): bool
    {
        if (!isset($query['cache'])) {
            return true;
        }

        $value = strtolower((string) $query['cache']);

        return !in_array($value, ['0', 'false'], true);
    }

    private function parseCacheTtlOverride(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $ttl = (int) $value;

        return $ttl < 0 ? 0 : $ttl;
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, string>
     */
    private function inferColumns(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $first = $data[0];
        if (!is_array($first)) {
            return ['value'];
        }

        return array_keys($first);
    }

    private function makeCacheKey(string $key, array $filters): string
    {
        ksort($filters);

        return sprintf('evyapi:report:%s:%s', $key, sha1((string) json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $filters
     */
    private function runClosureReport(
        string $key,
        array $definition,
        array $filters,
        string $traceId,
        array $columns,
        bool $cacheEnabled,
        ?int $ttlOverride,
        bool &$cacheHit
    ): ReportResult {
        if (!isset($definition['runner']) || !is_callable($definition['runner'])) {
            throw new RuntimeException(sprintf('Runner nao configurado para %s.', $key));
        }

        $runner = $definition['runner'];
        $ttl = isset($definition['cache']) ? (int) $definition['cache'] : 0;
        if ($ttlOverride !== null) {
            $ttl = $ttlOverride;
        }

        if ($cacheEnabled && $ttl > 0 && $this->redis !== null) {
            $cacheKey = $this->makeCacheKey($key, $filters);

            try {
                $cached = $this->redis->get($cacheKey);
            } catch (PredisException) {
                $cached = null;
            }

            if (is_string($cached) && $cached !== '') {
                $payload = json_decode($cached, true);
                if (is_array($payload) && isset($payload['data'], $payload['summary'], $payload['meta'], $payload['columns'])) {
                    $cacheHit = true;

                    return new ReportResult(
                        $payload['data'],
                        $payload['summary'],
                        $payload['meta'] + ['cache_hit' => true],
                        $payload['columns']
                    );
                }
            }

            $result = $this->invokeRunner($runner, $filters, $traceId);
            $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? false;

            $payload = [
                'data' => $result->data,
                'summary' => $result->summary,
                'meta' => $result->meta,
                'columns' => $result->columns !== [] ? $result->columns : $columns,
            ];

            try {
                $this->redis->setex($cacheKey, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (PredisException) {
                // ignore cache errors
            }

            return $result;
        }

        $result = $this->invokeRunner($runner, $filters, $traceId);
        $result->meta['cache_hit'] = $result->meta['cache_hit'] ?? false;

        return $result;
    }

    private function invokeRunner(callable $runner, array $filters, string $traceId): ReportResult
    {
        $callable = Closure::fromCallable($runner);
        $reflection = new ReflectionFunction($callable);
        $parameterCount = $reflection->getNumberOfParameters();

        return match (true) {
            $parameterCount >= 3 => $callable($this->apiClient, $filters, $traceId),
            $parameterCount === 2 => $callable($this->apiClient, $filters),
            $parameterCount === 1 => $callable($filters),
            default => $callable(),
        };
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, mixed> $rows
     */
    private function createCsvStream(array $columns, array $rows): StreamInterface
    {
        $index = 0;
        $totalRows = count($rows);
        $buffer = '';

        $generator = function () use (&$index, $columns, $rows, $totalRows) {
            if ($index === 0) {
                $values = $columns;
            } else {
                $rowIndex = $index - 1;
                if ($rowIndex >= $totalRows) {
                    return false;
                }

                $row = $rows[$rowIndex];
                if (!is_array($row)) {
                    $row = ['value' => $row];
                }

                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    if ($value === null) {
                        $value = '';
                    }

                    $values[] = (string) $value;
                }
            }

            $index++;

            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                return '';
            }

            fputcsv($handle, $values);
            rewind($handle);
            $line = stream_get_contents($handle) ?: '';
            fclose($handle);

            return $line;
        };

        return new PumpStream(function ($length) use (&$buffer, $generator) {
            while (strlen($buffer) < $length) {
                $chunk = $generator();
                if ($chunk === false || $chunk === null) {
                    break;
                }
                $buffer .= $chunk;
            }

            if ($buffer === '') {
                return false;
            }

            $slice = substr($buffer, 0, $length);
            $buffer = (string) substr($buffer, strlen($slice));

            return $slice;
        });
    }
}
