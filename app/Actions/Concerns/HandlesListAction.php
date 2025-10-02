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

        $links = [
            'self' => (string) $uri,
            'first' => $this->buildPageUri($uri, $queryParams, 1, $options->all),
            'prev' => $currentPage > 1 ? $this->buildPageUri($uri, $queryParams, $currentPage - 1, $options->all) : null,
            'next' => null,
            'last' => null,
        ];

        if ($options->all) {
            $links['first'] = (string) $uri;
            return $links;
        }

        $totalPages = $meta['total_pages'] ?? null;
        if (is_int($totalPages) && $totalPages > 0) {
            if ($currentPage < $totalPages) {
                $links['next'] = $this->buildPageUri($uri, $queryParams, $currentPage + 1, $options->all);
            }
            $links['last'] = $this->buildPageUri($uri, $queryParams, $totalPages, $options->all);
        } else {
            if (isset($crmLinks['next']) && is_string($crmLinks['next']) && $crmLinks['next'] !== '') {
                $nextQuery = $this->extractQueryFromLink($crmLinks['next']);
                if ($nextQuery !== null && isset($nextQuery['page'])) {
                    $nextPage = (int) $nextQuery['page'];
                    if ($nextPage > 0) {
                        $links['next'] = $this->buildPageUri($uri, $queryParams, $nextPage, $options->all);
                    }
                }
            }
            if (isset($crmLinks['last']) && is_string($crmLinks['last']) && $crmLinks['last'] !== '') {
                $lastQuery = $this->extractQueryFromLink($crmLinks['last']);
                if ($lastQuery !== null && isset($lastQuery['page'])) {
                    $lastPage = (int) $lastQuery['page'];
                    if ($lastPage > 0) {
                        $links['last'] = $this->buildPageUri($uri, $queryParams, $lastPage, $options->all);
                    }
                }
            }
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    protected function buildPageUri(UriInterface $uri, array $queryParams, int $page, bool $all): string
    {
        if ($page < 1) {
            $page = 1;
        }

        $queryParams['page']['number'] = $page;
        if ($all) {
            $queryParams['all'] = 'true';
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
