<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class ListSchoolsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly SchoolService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/escolas",
     *    tags={"Escolas"},
     *     summary="Lista de escolas com filtros e paginação",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="fetch", in="query", @OA\Schema(type="string", enum={"all"})),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", description="Campos: nome, -nome, total_alunos, -total_alunos, panfletagem, cidade", @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[cidade_id][]", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="filter[bairro_id][]", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="filter[status]", in="query", @OA\Schema(type="string", enum={"pendente","feito","todos"})),
     *     @OA\Parameter(name="filter[tipo][]", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[periodos][]", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Lista paginada de escolas"),
     *     @OA\Response(response=422, description="Parametros invalidos"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapSchools($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable $exception) {
            $this->logger->error('schools.list.query_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel processar os filtros.');
        }

        try {
            $result = $this->service->list($options);
        } catch (Throwable $exception) {
            $this->logger->error('schools.list.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar as escolas.');
        }

        $meta = $result['meta'];
        $meta['elapsed_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
        $meta['source'] = 'database';
        $meta['filters'] = $options->crmQuery['filters'] ?? [];

        $links = $this->buildLinks($request, $options, $meta, []);

        $response = $this->responder->successList(
            $response,
            $result['data'],
            $meta,
            $links,
            $traceId
        );

        return $response->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Count', (string) $result['total']);
    }
}
