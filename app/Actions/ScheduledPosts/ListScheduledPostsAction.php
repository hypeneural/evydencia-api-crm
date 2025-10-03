<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Cache\ScheduledPostCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ListScheduledPostsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ScheduledPostCache $cache,
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
            $options = $this->queryMapper->mapScheduledPosts($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        $signature = $this->buildSignature($options);
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        $cached = $this->cache->get($signature);
        if ($cached !== null) {
            $etag = $cached['etag'] ?? null;
            if ($etag !== null && $etag !== '' && $this->compareEtags($etag, $ifNoneMatch)) {
                return $response
                    ->withStatus(304)
                    ->withHeader('Trace-Id', $traceId)
                    ->withHeader('X-Request-Id', $traceId)
                    ->withHeader('ETag', $etag)
                    ->withHeader('Cache-Control', 'private, max-age=60');
            }
        }

        try {
            $result = $this->service->list($options, $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to list scheduled posts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar os agendamentos.');
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = $result['meta'];
        $meta['elapsed_ms'] = $elapsedMs;
        $links = $this->buildLinks($request, $options, $meta, []);

        $response = $this->responder->successList(
            $response,
            $result['data'],
            $meta,
            $links,
            $traceId
        );

        $etag = $this->generateEtag($result['max_updated_at'], $result['total'], $signature);
        if ($etag !== null) {
            $payload = [
                'data' => $result['data'],
                'meta' => $meta,
                'links' => $links,
                'total' => $result['total'],
            ];
            $this->cache->set($signature, $payload, $etag);
            $response = $response->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=60');
        }

        return $response
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Count', (string) $result['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSignature(QueryOptions $options): array
    {
        return [
            'filters' => $options->crmQuery['filters'] ?? [],
            'page' => $options->page,
            'per_page' => $options->perPage,
            'fetch_all' => $options->fetchAll,
            'sort' => $options->sort,
        ];
    }

    private function generateEtag(?string $maxUpdatedAt, int $total, array $signature): ?string
    {
        if ($maxUpdatedAt === null) {
            return null;
        }

        $payload = json_encode([
            'version' => $maxUpdatedAt,
            'total' => $total,
            'signature' => $signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return null;
        }

        return '"' . sha1($payload) . '"';
    }

    private function compareEtags(string $etag, string $header): bool
    {
        if ($header === '') {
            return false;
        }

        $normalizedHeader = trim($header, '"');
        $normalizedEtag = trim($etag, '"');

        return $normalizedHeader === $normalizedEtag;
    }
}
