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
    private const ALLOWED_STATUSES = ['waiting_product_retrieve'];


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
     */

    public function __invoke(Request $request, Response $response): Response

    {

        $traceId = $this->resolveTraceId($request);

        $uuid = $this->resolveUuid($request);



        $payload = $this->normalizePayload($request->getParsedBody());

        $validationErrors = $this->validate($uuid, $payload);

        if ($validationErrors !== []) {

            return $this->responder->validationError($response, $traceId, $validationErrors);

        }



        $status = $this->sanitizeStatus($payload['status'] ?? null);
        if ($status === null) {
            return $this->responder->validationError($response, $traceId, [
                [
                    'field' => 'status',
                    'message' => 'Status invalido.',
                ],
            ]);
        }

        $note = isset($payload['note']) ? $this->sanitizeOptionalString($payload['note']) : null;



        try {

            $result = $this->orderService->updateOrderStatus($uuid, $status, $note, $traceId);

        } catch (CrmUnavailableException) {

            return $this->responder->badGateway($response, $traceId, 'CRM timeout');

        } catch (CrmRequestException $exception) {

            if ($exception->getStatusCode() === 404) {

                return $this->responder->notFound(

                    $response,

                    $traceId,

                    $this->resolveCrmMessage($exception->getPayload(), 'Pedido nao encontrado.')

                );

            }

            return $this->responder->badGateway(

                $response,

                $traceId,

                $this->resolveCrmMessage(

                    $exception->getPayload(),

                    sprintf('CRM error (status %d).', $exception->getStatusCode())

                )

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



        $rawStatus = $payload['status'] ?? null;


        $normalizedStatus = is_string($rawStatus) ? trim($rawStatus) : $rawStatus;


        try {

            v::stringType()->notEmpty()->length(2, 64)->setName('status')->assert($normalizedStatus);

        } catch (NestedValidationException $exception) {

            $errors[] = [

                'field' => 'status',

                'message' => $exception->getMessages()[0] ?? 'Status invalido.',

            ];

        }


        $sanitizedStatus = $this->sanitizeStatus($normalizedStatus);

        if ($sanitizedStatus !== null && !in_array($sanitizedStatus, self::ALLOWED_STATUSES, true)) {

            $errors[] = [

                'field' => 'status',

                'message' => sprintf(

                    'Status "%s" nao permitido. Statuses validos: %s.',

                    $sanitizedStatus,

                    implode(', ', self::ALLOWED_STATUSES)

                ),

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



    private function sanitizeStatus(mixed $value): ?string

    {

        if (!is_string($value)) {

            return null;

        }



        $status = trim($value);



        return $status === '' ? null : $status;

    }



    /**

     * @param array<string, mixed> $payload

     */

    private function resolveCrmMessage(array $payload, string $default): string

    {

        $errorNode = $payload['error'] ?? null;



        $possibleMessages = [

            $payload['message'] ?? null,

            is_string($errorNode) ? $errorNode : null,

            is_array($errorNode) ? ($errorNode['message'] ?? null) : null,

            is_array($errorNode) ? ($errorNode['detail'] ?? null) : null,

        ];



        foreach ($possibleMessages as $message) {

            if (is_string($message) && $message !== '') {

                return $message;

            }

        }



        return $default;

    }

}



