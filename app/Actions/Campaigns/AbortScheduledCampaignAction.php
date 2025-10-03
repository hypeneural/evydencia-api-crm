<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Application\Services\CampaignService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

final class AbortScheduledCampaignAction
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/campaigns/schedule/{id}/abort",
     *     tags={"Campaigns"},
     *     summary="Aborta uma campanha agendada",
     *     @OA\Parameter(name="id", in="path", required=true, description="Identificador da agenda.", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Campanha abortada", @OA\JsonContent(ref="#/components/schemas/GenericResourceResponse")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro no CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $scheduleId = $this->resolveScheduleId($request);

        if ($scheduleId === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'id', 'message' => 'Identificador da campanha agendada e obrigatorio.'],
            ]);
        }

        try {
            $result = $this->campaignService->abortScheduledCampaign($scheduleId, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while aborting scheduled campaign', [
                'trace_id' => $traceId,
                'schedule_id' => $scheduleId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Unexpected error while aborting scheduled campaign.'
            );
        }

        $data = $result['data'] ?? [];
        if (!is_array($data)) {
            $data = ['result' => $data];
        }

        return $this->responder->successResource(
            $response,
            $data,
            $traceId,
            ['source' => 'crm'],
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

    private function resolveScheduleId(Request $request): string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $id = $route?->getArgument('id');

        return is_string($id) ? trim($id) : '';
    }
}

