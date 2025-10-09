<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ListPasswordsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly PasswordService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/passwords",
     *     tags={"Passwords"},
     *     summary="Lista senhas com filtros e paginacao",
     *     @OA\Parameter(name="page", in="query", description="Pagina atual", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="per_page", in="query", description="Itens por pagina (1-200)", @OA\Schema(type="integer", minimum=1, maximum=200)),
     *     @OA\Parameter(name="fetch", in="query", description="Use 'all' para retornar todos os resultados", @OA\Schema(type="string", enum={"all"})),
     *     @OA\Parameter(name="q", in="query", description="Busca livre em usuário, local, link e descrição", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", description="Ordenação ex.: usuario,-updated_at", @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[tipo]", in="query", description="Filtra por tipo de credencial", @OA\Schema(type="string", enum={"Sistema","Rede Social","E-mail"})),
     *     @OA\Parameter(name="filter[local]", in="query", description="Filtra por plataforma/serviço", @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[verificado]", in="query", description="1 para verificados, 0 para não verificados", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="filter[created_at][gte]", in="query", description="Data de criação >= (YYYY-MM-DD)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[created_at][lte]", in="query", description="Data de criação <= (YYYY-MM-DD)", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Lista paginada de senhas", @OA\JsonContent(ref="#/components/schemas/PasswordListResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapPasswords($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable $exception) {
            $this->logger->error('passwords.list.query_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel processar os filtros.');
        }

        try {
            $result = $this->service->list($options, $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.list.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar as senhas.');
        }

        $meta = $result['meta'];
        $meta['elapsed_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
        $meta['source'] = 'database';

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
            ->withHeader('X-Total-Count', (string) $result['total']);
    }
}
