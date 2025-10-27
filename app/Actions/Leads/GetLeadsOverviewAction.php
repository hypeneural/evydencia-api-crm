<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Application\Services\LeadOverviewService;
use App\Application\Support\ApiResponder;
use App\Application\Support\LeadOverviewRequestMapper;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetLeadsOverviewAction
{
    public function __construct(
        private readonly LeadOverviewService $leadOverviewService,
        private readonly LeadOverviewRequestMapper $requestMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/leads/overview",
     *     tags={"Leads"},
     *     summary="Resumo dos leads por campanha",
     *     @OA\Parameter(
     *         name="campaign_id",
     *         in="query",
     *         required=true,
     *         description="Um ou mais IDs de campanha (separados por virgula).",
     *         @OA\Schema(type="string", example="416,415")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Quantidade de leads recentes a retornar (padrao 10, maximo 100).",
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=10)
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=false,
     *         description="Filtro de data inicial (lead.created_at) em ISO-8601.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=false,
     *         description="Filtro de data final (lead.created_at) em ISO-8601.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="dedupe_by",
     *         in="query",
     *         required=false,
     *         description="Chave usada para desduplicar leads (lead_id ou whatsapp).",
     *         @OA\Schema(type="string", enum={"lead_id", "whatsapp"}, default="lead_id")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Resumo de leads gerado",
     *         @OA\JsonContent(ref="#/components/schemas/GenericResourceResponse")
     *     ),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro ao consultar o CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);

        try {
            $options = $this->requestMapper->map($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->leadOverviewService->getOverview($options, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while building lead overview', [
                'trace_id' => $traceId,
                'campaign_ids' => $options->campaignIds,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Unexpected error while building lead overview.'
            );
        }

        $meta = $result['meta'] ?? [];

        return $this->responder->successResource(
            $response,
            $result['data'] ?? [],
            $traceId,
            $meta,
            ['self' => (string) $request->getUri()]
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
}

