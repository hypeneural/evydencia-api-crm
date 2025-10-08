<?php

declare(strict_types=1);

namespace App\Actions\Labels;

use App\Application\Services\LabelService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\NotFoundException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

final class GenerateOrderLabelAction
{
    public function __construct(
        private readonly LabelService $labelService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/orders/{uuid}/label",
     *     tags={"Labels"},
     *     summary="Gera a etiqueta do pedido",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Identificador do pedido no CRM.",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Etiqueta gerada com sucesso",
     *         @OA\MediaType(mediaType="image/png", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Parametros invalidos",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erro interno",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     )
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $orderId = $this->resolveOrderId($request);

        if ($orderId === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'uuid', 'message' => 'Identificador do pedido e obrigatorio.'],
            ]);
        }

        try {
            $result = $this->labelService->generateLabel($orderId, $traceId);
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage() ?: 'Pedido nao encontrado.')
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to generate order label', [
                'trace_id' => $traceId,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel gerar a etiqueta.');
        }

        $stream = $result->stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$stream->eof()) {
            $body->write($stream->read(8192));
        }

        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Disposition', sprintf('inline; filename="%s"', $result->filename))
            ->withHeader('Content-Length', (string) $result->bytes)
            ->withHeader('X-Request-Id', $traceId);
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    private function resolveOrderId(Request $request): string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $uuid = $route?->getArgument('uuid');

        return is_string($uuid) ? trim($uuid) : '';
    }
}



