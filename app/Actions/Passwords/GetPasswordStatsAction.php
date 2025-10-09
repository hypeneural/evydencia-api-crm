<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetPasswordStatsAction
{
    public function __construct(
        private readonly PasswordService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/passwords/stats",
     *     tags={"Passwords"},
     *     summary="Retorna estatisticas agregadas das senhas",
     *     @OA\Response(response=200, description="Dados agregados", @OA\JsonContent(ref="#/components/schemas/PasswordStatsResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);

        try {
            $options = $this->queryMapper->mapPasswords($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (\Throwable $exception) {
            $this->logger->error('passwords.stats.query_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel processar os filtros.');
        }

        $filters = $options->crmQuery['filters'] ?? [];

        try {
            $stats = $this->service->stats($filters);
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.stats.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel obter as estatisticas.');
        }

        $meta = [
            'source' => 'database',
        ];

        $response = $this->responder->successResource(
            $response,
            $stats,
            $traceId,
            $meta,
            ['self' => (string) $request->getUri()]
        );

        return $response->withHeader('X-Request-Id', $traceId);
    }

    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}

