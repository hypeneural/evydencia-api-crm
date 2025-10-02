<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Application\DTO\QueryOptions;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UriInterface;

trait HandlesListAction
{
    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $crmLinks
     * @return array<string, mixed>
     */
    protected function buildLinks(Request $request, QueryOptions $options, array $meta, array $crmLinks): array
    {
        $uri = $request->getUri();
        $queryParams = $request->getQueryParams();
        $currentPage = (int) ($meta['page'] ?? $options->page);

        if ($options->fetchAll) {
            return [
                'self' => (string) $uri,
                'next' => null,
                'prev' => null,
            ];
        }

        $links = [
            'self' => (string) $uri,
            'next' => null,
            'prev' => $currentPage > 1 ? $this->buildPageUri($uri, $queryParams, $options, $currentPage - 1) : null,
        ];

        $totalPages = $meta['total_pages'] ?? null;
        if (is_int($totalPages) && $totalPages > 0) {
            if ($currentPage < $totalPages) {
                $links['next'] = $this->buildPageUri($uri, $queryParams, $options, $currentPage + 1);
            }

            return $links;
        }

        if (isset($crmLinks['next']) && is_string($crmLinks['next']) && $crmLinks['next'] !== '') {
            $nextQuery = $this->extractQueryFromLink($crmLinks['next']);
            if ($nextQuery !== null && isset($nextQuery['page'])) {
                $nextPage = (int) $nextQuery['page'];
                if ($nextPage > 0) {
                    $links['next'] = $this->buildPageUri($uri, $queryParams, $options, $nextPage);
                }
            }
        }

        if ($links['prev'] === null && isset($crmLinks['prev']) && is_string($crmLinks['prev']) && $crmLinks['prev'] !== '') {
            $prevQuery = $this->extractQueryFromLink($crmLinks['prev']);
            if ($prevQuery !== null && isset($prevQuery['page'])) {
                $prevPage = (int) $prevQuery['page'];
                if ($prevPage > 0) {
                    $links['prev'] = $this->buildPageUri($uri, $queryParams, $options, $prevPage);
                }
            }
        }

        return $links;
    }

    protected function buildPageUri(UriInterface $uri, array $queryParams, QueryOptions $options, int $page): string
    {
        if ($page < 1) {
            $page = 1;
        }

        unset($queryParams['page']);
        $queryParams['page'] = $page;

        $perPage = isset($queryParams['per_page']) ? (int) $queryParams['per_page'] : $options->perPage;
        if ($perPage <= 0) {
            $perPage = $options->perPage;
        }
        $queryParams['per_page'] = $perPage;

        if ($options->fetchAll) {
            $queryParams['fetch'] = 'all';
        } else {
            unset($queryParams['fetch'], $queryParams['all']);
        }

        $queryString = http_build_query($queryParams);

        return (string) $uri->withQuery($queryString);
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

