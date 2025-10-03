<?php

declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ListBlacklistAction
{
    use HandlesListAction;

    public function __construct(
        private readonly BlacklistService $service,
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
            $options = $this->queryMapper->mapBlacklist($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->service->list($options, $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to list blacklist entries', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar a blacklist.');
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

        return $response
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Count', (string) ($result['total'] ?? 0));
    }
}
