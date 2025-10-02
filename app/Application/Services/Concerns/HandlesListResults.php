<?php

declare(strict_types=1);

namespace App\Application\Services\Concerns;

use App\Application\DTO\QueryOptions;

trait HandlesListResults
{
    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    protected function applySort(array $data, array $sortRules): array
    {
        if ($sortRules === [] || $data === []) {
            return $data;
        }

        usort($data, function ($left, $right) use ($sortRules): int {
            foreach ($sortRules as $rule) {
                $field = $rule['field'];
                $direction = $rule['direction'];
                $leftValue = $this->resolveFieldValue($left, $field);
                $rightValue = $this->resolveFieldValue($right, $field);
                $comparison = $this->compareValues($leftValue, $rightValue);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     * @param array<int, string> $fields
     * @return array<int, mixed>
     */
    protected function applyProjection(array $data, array $fields): array
    {
        if ($fields === []) {
            return $data;
        }

        $projected = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                $projected[] = $item;
                continue;
            }

            $projection = [];
            foreach ($fields as $path) {
                $value = $this->resolveFieldValue($item, $path);
                if ($value === null) {
                    continue;
                }
                $this->assignByPath($projection, $path, $value);
            }
            $projected[] = $projection;
        }

        return $projected;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    protected function extractData(array $response): array
    {
        $data = $response['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    protected function extractMeta(array $response): array
    {
        $meta = $response['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    protected function extractLinks(array $response): array
    {
        $links = $response['links'] ?? null;

        return is_array($links) ? $links : [];
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

    protected function buildMeta(array $crmMeta, QueryOptions $options, int $count): array
    {
        $totalItems = $crmMeta['total_items'] ?? $crmMeta['total'] ?? $crmMeta['totalItems'] ?? null;
        if (!is_int($totalItems) && !is_float($totalItems)) {
            $totalItems = is_string($totalItems) ? (int) $totalItems : null;
        }

        $totalPages = $crmMeta['total_pages'] ?? $crmMeta['last_page'] ?? null;
        if (!is_int($totalPages) && !is_float($totalPages)) {
            $totalPages = is_string($totalPages) ? (int) $totalPages : null;
        }

        $page = (int) (
            $crmMeta['page']
            ?? $crmMeta['current_page']
            ?? $options->page
        );

        $perPage = $options->perPage;

        if ($options->fetchAll) {
            $page = 1;
            $totalItems = $totalItems ?? $count;
            $totalPages = 1;
        }

        return [
            'page' => $page,
            'size' => $perPage,
            'per_page' => $perPage,
            'count' => $count,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
        ];
    }

    private function resolveFieldValue(mixed $data, string $path): mixed
    {
        if (!is_array($data) || $path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function assignByPath(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $pointer = &$data;
        foreach ($segments as $segment) {
            if (!isset($pointer[$segment]) || !is_array($pointer[$segment])) {
                $pointer[$segment] = [];
            }
            $pointer = &$pointer[$segment];
        }
        $pointer = $value;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return -1;
        }

        if ($right === null) {
            return 1;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        if ($this->isDateTimeString($left) && $this->isDateTimeString($right)) {
            return strtotime((string) $left) <=> strtotime((string) $right);
        }

        return strnatcasecmp((string) $left, (string) $right);
    }

    private function isDateTimeString(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return strtotime($value) !== false;
    }
}

