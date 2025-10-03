<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\CampaignService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use OpenApi\Annotations as OA;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetCampaignScheduleAction
{
    use HandlesListAction;

    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/campaigns/schedule",
     *     tags={"Campaigns"},
     *     summary="Lista campanhas agendadas",
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", minimum=1, default=1)),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=200, default=50)),
     *     @OA\Parameter(name="fetch", in="query", required=false, description="Use 'all' para buscar todas as páginas.", @OA\Schema(type="string", enum={"all"})),
     *     @OA\Parameter(name="status", in="query", required=false, description="Filtra por status (scheduled, running, finished).", @OA\Schema(type="string")),
     *     @OA\Parameter(name="start_at", in="query", required=false, description="Data mínima (YYYY-MM-DD).", @OA\Schema(type="string")),
     *     @OA\Parameter(name="finish_at", in="query", required=false, description="Data máxima (YYYY-MM-DD).", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Lista de agendamentos", @OA\JsonContent(ref="#/components/schemas/CampaignScheduleListResponse")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro no CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapCampaignSchedule($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->campaignService->fetchSchedule($options, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while fetching campaign schedule', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while fetching campaign schedule.');
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
            $traceId
        );
    }
}

