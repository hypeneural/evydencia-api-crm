<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Reports\ReportHelpers;
use App\Application\Reports\ReportInterface;
use App\Application\Reports\ReportResult;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Http\EvydenciaApiClient;
use Closure;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use PDO;
use Predis\Client as PredisClient;
use Predis\Exception\PredisException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validatable;
use Respect\Validation\Validator as v;
use RuntimeException;

final class ReportEngine
{
    private const DEFAULT_TTL = 900;
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 500;

    private const CRM_FILTER_WHITELIST = [
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
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    /**
     * @var array<string, ReportInterface>
     */
    private array $instances = [];

    private ReportHelpers $helpers;

    public function __construct(
        private readonly EvydenciaApiClient $crm,
        private readonly QueryMapper $queryMapper,
        private readonly ?PredisClient $redis,
        private readonly LoggerInterface $logger,
        private readonly ?PDO $pdo,
        private readonly string $definitionsPath
    ) {
        $this->definitions = $this->loadDefinitions($definitionsPath);
        $this->helpers = new ReportHelpers();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $items = [];
        foreach ($this->definitions as $key => $definition) {
            $report = $this->resolveReportInstance($key, $definition);
            $columns = $definition['columns'] ?? [];
            if ($report instanceof ReportInterface) {
                $columns = $report->columns();
            }

            $items[] = [
                'key' => $key,
                'title' => $definition['title'] ?? ($report?->title() ?? $key),
                'description' => $definition['description'] ?? ($report?->description() ?? ''),
                'columns' => $columns,
                'params' => $definition['params'] ?? ($report?->params() ?? []),
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
        $report = $this->resolveReportInstance($key, $definition);
        $schema = $this->resolveParamsSchema($definition, $report);
        $columnsMeta = $this->resolveColumnsMeta($definition, $report);
        $description = $definition['description'] ?? ($report?->description() ?? '');

        [$input, $errors] = $this->normalizeParams($schema, $query);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $page = (int) ($input['page'] ?? self::DEFAULT_PAGE);
        $perPage = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);
        $sort = $input['sort'] ?? null;
        $dir = $input['dir'] ?? 'asc';
        $cacheEnabled = (bool) ($input['_cache_enabled'] ?? true);
        $cacheTtlOverride = $input['_cache_ttl'] ?? null;
        $fetchAll = ($input['_fetch_all'] ?? false) === true;
        unset($input['_cache_enabled'], $input['_cache_ttl'], $input['_fetch_all']);

        $cacheKey = $this->makeCacheKey($key, $this->cacheRelevantQuery($input, $page, $perPage));
        $ttl = $this->resolveTtl($definition, $report, $cacheTtlOverride);

        $cacheData = null;
        if ($cacheEnabled && $ttl > 0 && $this->redis !== null) {
            try {
                $cached = $this->redis->get($cacheKey);
            } catch (PredisException) {
                $cached = null;
            }
            if (is_string($cached) && $cached !== '') {
                $payload = json_decode($cached, true);
                if (is_array($payload) && isset($payload['data'], $payload['summary'], $payload['meta'], $payload['columns'])) {
                    $meta = $payload['meta'];
                    $meta['cache'] = ['hit' => true, 'key' => $cacheKey];
                    $meta['description'] = $description;
                    return new ReportResult($payload['data'], $payload['summary'], $meta, $payload['columns']);
                }
            }
        }

        $startedAt = microtime(true);
        $this->logger->info('report.run.start', [
            'report_key' => $key,
            'trace_id' => $traceId,
            'params' => array_keys($input),
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $input['trace_id'] = $traceId;
        $input['page'] = $page;
        $input['per_page'] = $perPage;
        $input['sort'] = $sort;
        $input['dir'] = $dir;
        $input['fetch'] = $fetchAll ? 'all' : 'page';

        $result = $this->executeReport($definition, $report, $input, $traceId, $columnsMeta);
        $result->meta['cache'] = ['hit' => false, 'key' => $cacheKey];
        $result->meta['page'] = $result->meta['page'] ?? $page;
        $result->meta['per_page'] = $result->meta['per_page'] ?? $perPage;
        $result->meta['sort'] = $sort;
        $result->meta['dir'] = $dir;
        $result->meta['description'] = $description;
        $result->meta['trace_id'] = $traceId;
        $result->meta['source'] = $result->meta['source'] ?? 'engine';

        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        $result->meta['took_ms'] = $result->meta['took_ms'] ?? $elapsed;

        if ($cacheEnabled && $ttl > 0 && $this->redis !== null) {
            $payload = [
                'data' => $result->data,
                'summary' => $result->summary,
                'meta' => $result->meta,
                'columns' => $result->columns,
            ];
            try {
                $this->redis->setex($cacheKey, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (PredisException) {
                // ignore cache failures
            }
        }

        $this->logger->info('report.run.finish', [
            'report_key' => $key,
            'trace_id' => $traceId,
            'took_ms' => $elapsed,
            'total' => $result->meta['total'] ?? count($result->data),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function export(string $key, array $query, string $format, string $traceId): StreamInterface
    {
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'json', 'ndjson'], true)) {
            throw new ValidationException([[ 'field' => 'format', 'message' => 'Formato invalido. Use csv, json ou ndjson.' ]]);
        }

        $query['fetch'] = 'all';
        $result = $this->run($key, $query, $traceId);

        return match ($format) {
            'csv' => $this->exportCsv($result),
            'ndjson' => $this->exportNdJson($result),
            default => Utils::streamFor(json_encode($result->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        };
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
            throw new RuntimeException('Invalid report definitions file.');
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

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveReportInstance(string $key, array $definition): ?ReportInterface
    {
        if (($definition['type'] ?? null) !== 'class') {
            return null;
        }

        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $className = $definition['class'] ?? null;
        if (!is_string($className) || !class_exists($className)) {
            throw new RuntimeException(sprintf('Classe invalida para relatorio %s.', $key));
        }

        $instance = new $className($this->crm, $this->pdo, $this->redis, $this->logger);
        if (!$instance instanceof ReportInterface) {
            throw new RuntimeException(sprintf('Relatorio %s deve implementar ReportInterface.', $key));
        }

        return $this->instances[$key] = $instance;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, array<string, mixed>>
     */
    private function resolveParamsSchema(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->params();
        }

        return $definition['params'] ?? [];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    private function resolveColumnsMeta(array $definition, ?ReportInterface $report): array
    {
        if ($report !== null) {
            return $report->columns();
        }

        return $definition['columns'] ?? [];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $query
     * @return array{0: array<string, mixed>, 1: array<int, array<string, string>>}
     */
    private function normalizeParams(array $schema, array $query): array
    {
        $errors = [];
        $resolved = [];

        foreach ($schema as $name => $definition) {
            $type = $definition['type'] ?? 'string';
            $default = $definition['default'] ?? null;
            $required = (bool) ($definition['required'] ?? false);
            $enum = $definition['enum'] ?? null;

            $value = $query[$name] ?? null;
            if ($value === null && $default !== null) {
                $value = is_callable($default) ? $default() : $default;
            }

            if ($required && ($value === null || $value === '')) {
                $errors[] = ['field' => $name, 'message' => 'Parametro obrigatorio.'];
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            try {
                $resolved[$name] = $this->castValue($name, $value, $type, $definition);
            } catch (InvalidParameterException $exception) {
                $errors[] = ['field' => $name, 'message' => $exception->getMessage()];
                continue;
            }

            if (is_array($enum) && !in_array($resolved[$name], $enum, true)) {
                $errors[] = ['field' => $name, 'message' => 'Valor nao permitido.'];
            }
        }

        foreach ($query as $key => $value) {
            if (isset($resolved[$key]) || isset($schema[$key])) {
                continue;
            }

            if (in_array($key, self::CRM_FILTER_WHITELIST, true)) {
                if (is_array($value)) {
                    $resolved[$key] = array_map(static fn ($item) => is_scalar($item) ? (string) $item : $item, $value);
                } else {
                    $resolved[$key] = is_scalar($value) ? (string) $value : $value;
                }
            }
        }

        $resolved['page'] = $this->normalizePositiveInt($query['page'] ?? null, self::DEFAULT_PAGE);
        $resolved['per_page'] = min(self::MAX_PER_PAGE, $this->normalizePositiveInt($query['per_page'] ?? null, self::DEFAULT_PER_PAGE));
        $resolved['sort'] = is_string($query['sort'] ?? null) ? trim((string) $query['sort']) : null;
        $resolved['dir'] = in_array(strtolower((string) ($query['dir'] ?? 'asc')), ['asc', 'desc'], true)
            ? strtolower((string) ($query['dir'] ?? 'asc'))
            : 'asc';
        $resolved['_cache_enabled'] = !in_array(strtolower((string) ($query['cache'] ?? '1')), ['0', 'false'], true);
        $resolved['_cache_ttl'] = isset($query['cache_ttl']) && is_numeric($query['cache_ttl'])
            ? (int) $query['cache_ttl']
            : null;
        $resolved['_fetch_all'] = isset($query['fetch']) && strtolower((string) $query['fetch']) === 'all';

        return [$resolved, $errors];
    }

    private function normalizePositiveInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $int = (int) $value;

        return $int > 0 ? $int : $default;
    }

    private function castValue(string $name, mixed $value, string $type, array $definition): mixed
    {
        return match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : throw new InvalidParameterException('Deve ser inteiro.'),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float) $value : throw new InvalidParameterException('Deve ser numerico.'),
            'bool' => $this->castBool($value),
            'date' => $this->castDate($value, $definition['format'] ?? 'Y-m-d'),
            'array<string>' => $this->castArrayString($value),
            default => is_scalar($value) ? trim((string) $value) : throw new InvalidParameterException('Valor invalido.'),
        };
    }

    private function castBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function castDate(mixed $value, string $format): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidParameterException('Data invalida.');
        }

        $validator = v::date($format);
        try {
            $validator->assert($value);
        } catch (NestedValidationException $exception) {
            throw new InvalidParameterException($exception->getMessage());
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function castArrayString(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));
        }

        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $trim = trim((string) $item);
                    if ($trim !== '') {
                        $list[] = $trim;
                    }
                }
            }

            return $list;
        }

        throw new InvalidParameterException('Deve ser lista de strings.');
    }

    private function resolveTtl(array $definition, ?ReportInterface $report, ?int $override): int
    {
        if ($override !== null) {
            return max(0, $override);
        }

        if ($report !== null) {
            return max(0, $report->cacheTtl());
        }

        if (isset($definition['cache'])) {
            return max(0, (int) $definition['cache']);
        }

        return self::DEFAULT_TTL;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $input
     */
    private function executeReport(array $definition, ?ReportInterface $report, array $input, string $traceId, array $columnsMeta): ReportResult
    {
        if (($definition['type'] ?? null) === 'closure') {
            $runner = $definition['runner'] ?? null;
            if (!is_callable($runner)) {
                throw new RuntimeException('Runner nao configurado para relatorio closure.');
            }

            /** @var callable $runner */
            $result = $runner($this->crm, $this->pdo, $input, $this->helpers);
            if (!$result instanceof ReportResult) {
                throw new RuntimeException('Runner deve retornar ReportResult.');
            }

            if ($result->columns === []) {
                $result->columns = $columnsMeta;
            }

            return $result;
        }

        if ($report === null) {
            throw new RuntimeException('Relatorio nao configurado.');
        }

        $result = $report->run($input);
        if ($result->columns === []) {
            $result->columns = $report->columns();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheRelevantQuery(array $input, int $page, int $perPage): array
    {
        $normalized = $input;
        unset($normalized['trace_id']);
        if (($normalized['fetch'] ?? null) !== 'all') {
            $normalized['page'] = $page;
            $normalized['per_page'] = $perPage;
        }

        return $normalized;
    }

    private function makeCacheKey(string $key, array $query): string
    {
        ksort($query);

        return sprintf('evyapi:report:%s:%s', $key, sha1((string) json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private function exportCsv(ReportResult $result): StreamInterface
    {
        $columns = $result->columns;
        if ($columns === []) {
            $columns = $this->inferColumns($result->data);
        }

        $headers = array_map(static fn ($column) => $column['key'] ?? $column['label'] ?? (is_array($column) ? '' : $column), $columns);
        $headerLine = $this->csvLine($headers);

        $index = 0;
        $buffer = '';
        $total = count($result->data);

        return new PumpStream(function ($length) use (&$index, &$buffer, $total, $result, $columns, $headerLine) {
            if ($index === 0) {
                $buffer .= $headerLine;
                $index++;
            }

            while (strlen($buffer) < $length && ($index - 1) < $total) {
                $row = $result->data[$index - 1];
                if (!is_array($row)) {
                    $row = ['value' => $row];
                }

                $values = [];
                foreach ($columns as $column) {
                    $key = is_array($column) ? ($column['key'] ?? null) : $column;
                    $values[] = $key !== null ? (string) ($row[$key] ?? '') : '';
                }

                $buffer .= $this->csvLine($values);
                $index++;
            }

            if ($buffer === '') {
                return false;
            }

            $slice = substr($buffer, 0, $length);
            $buffer = (string) substr($buffer, strlen($slice));

            return $slice;
        });
    }

    private function exportNdJson(ReportResult $result): StreamInterface
    {
        $index = 0;
        $total = count($result->data);
        $buffer = '';

        return new PumpStream(function ($length) use (&$index, $total, &$buffer, $result) {
            while (strlen($buffer) < $length && $index < $total) {
                $buffer .= json_encode($result->data[$index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $index++;
            }

            if ($buffer === '') {
                return false;
            }

            $slice = substr($buffer, 0, $length);
            $buffer = (string) substr($buffer, strlen($slice));

            return $slice;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inferColumns(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $first = $data[0];
        if (!is_array($first)) {
            return [['key' => 'value', 'label' => 'Value', 'type' => 'string']];
        }

        $columns = [];
        foreach (array_keys($first) as $key) {
            $columns[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'type' => 'string'];
        }

        return $columns;
    }

    /**
     * @param array<int, string> $values
     */
    private function csvLine(array $values): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $values);
        rewind($handle);
        $line = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $line;
    }
}

final class InvalidParameterException extends RuntimeException
{
}
