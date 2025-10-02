<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Application\DTO\QueryOptions;
use App\Application\Services\OrderService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SearchOrdersAction
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapOrdersSearch($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->orderService->searchOrders($options, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while searching orders', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while searching orders.');
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = $result['meta'];
        $meta['elapsed_ms'] = $elapsedMs;
        $links = $this->buildLinks($request, $options, $meta, $result['crm_links'] ?? []);

        return $this->responder->successList(
            $response,
            $result['data'],
            $meta,
            $links,
            $traceId,
            'orders/search'
        );
    }

    private function resolveTraceId(Request $request): string
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
    private function buildLinks(Request $request, QueryOptions $options, array $meta, array $crmLinks): array
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
    private function buildPageUri(UriInterface $uri, array $queryParams, int $page, bool $all): string
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
    private function extractQueryFromLink(string $link): ?array
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
