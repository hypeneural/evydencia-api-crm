<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Application\Services\OrderService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;
use Slim\Routing\RouteContext;

final class UpdateOrderStatusAction
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Put(
     *     path="/v1/orders/{uuid}/status",
     *     tags={"Orders"},
     *     summary="Atualiza o status do pedido no CRM",
     *     @OA\Parameter(name="uuid", in="path", required=true, description="Identificador do pedido.", @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/OrderStatusUpdatePayload")),
     *     @OA\Response(response=200, description="Status atualizado", @OA\JsonContent(ref="#/components/schemas/GenericResourceResponse")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro ao consultar CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $uuid = $this->resolveUuid($request);

        $payload = $this->normalizePayload($request->getParsedBody());
        $validationErrors = $this->validate($uuid, $payload);
        if ($validationErrors !== []) {
            return $this->responder->validationError($response, $traceId, $validationErrors);
        }

        $status = trim((string) $payload['status']);
        $note = isset($payload['note']) ? $this->sanitizeOptionalString($payload['note']) : null;

        try {
            $result = $this->orderService->updateOrderStatus($uuid, $status, $note, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to update order status', [
                'trace_id' => $traceId,
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while updating order status.');
        }

        $resource = is_array($result) ? $result : ['result' => $result];

        return $this->responder->successResource(
            $response,
            $resource,
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

    private function resolveUuid(Request $request): string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $uuid = $route?->getArgument('uuid');

        return is_string($uuid) ? trim($uuid) : '';
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        return $input;
    }

    /**
     * @param string $uuid
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private function validate(string $uuid, array $payload): array
    {
        $errors = [];

        if ($uuid === '') {
            $errors[] = [
                'field' => 'uuid',
                'message' => 'Obrigatorio informar o identificador do pedido.',
            ];
        }

        try {
            v::stringType()->notEmpty()->length(2, 64)->setName('status')->assert($payload['status'] ?? null);
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'status',
                'message' => $exception->getMessages()[0] ?? 'Status invalido.',
            ];
        }

        if (array_key_exists('note', $payload) && $payload['note'] !== null) {
            try {
                v::stringType()->length(1, 255)->setName('note')->assert($payload['note']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'note',
                    'message' => $exception->getMessages()[0] ?? 'Observacao invalida.',
                ];
            }
        }

        return $errors;
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}

