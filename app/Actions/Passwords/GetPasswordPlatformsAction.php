<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use OpenApi\Annotations as OA;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetPasswordPlatformsAction
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
     *     path="/v1/passwords/platforms",
     *     tags={"Passwords"},
     *     summary="Lista plataformas mais utilizadas nas senhas",
     *     @OA\Parameter(name="limit", in="query", description="Limite maximo de plataformas", @OA\Schema(type="integer", minimum=1, maximum=200)),
     *     @OA\Parameter(name="min_count", in="query", description="Quantidade minima para aparecer na lista", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(response=200, description="Lista de plataformas", @OA\JsonContent(ref="#/components/schemas/PasswordPlatformsResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $queryParams = $request->getQueryParams();

        try {
            $options = $this->queryMapper->mapPasswords($queryParams);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (\Throwable $exception) {
            $this->logger->error('passwords.platforms.query_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel processar os filtros.');
        }

        $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 20;
        if ($limit <= 0) {
            $limit = 20;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $minCount = null;
        if (isset($queryParams['min_count'])) {
            $minCountCandidate = (int) $queryParams['min_count'];
            if ($minCountCandidate > 0) {
                $minCount = $minCountCandidate;
            }
        }

        $filters = $options->crmQuery['filters'] ?? [];

        try {
            $data = $this->service->platforms($filters, $limit, $minCount);
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.platforms.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar as plataformas.');
        }

        $meta = [
            'source' => 'database',
            'count' => count($data),
        ];

        $response = $this->responder->successResource(
            $response,
            $data,
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

