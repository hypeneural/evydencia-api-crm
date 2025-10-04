<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Application\Services\WhatsAppService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ZapiRequestException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;

final class RemoveContactsAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/whatsapp/contacts/remove",
     *     tags={"WhatsApp"},
     *     summary="Remove contatos da agenda vinculada ao WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="array", @OA\Items(type="string", example="5511999999999"))),
     *     @OA\Response(response=200, description="Contatos removidos", @OA\JsonContent(ref="#/components/schemas/WhatsAppGenericResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $validationErrors = $this->validate($payload);
        if ($validationErrors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $validationErrors)
                ->withHeader('X-Request-Id', $traceId);
        }

        $phones = array_map([$this, 'sanitizePhone'], $payload);

        try {
            $result = $this->service->removeContacts($traceId, $phones);
        } catch (ZapiRequestException $exception) {
            $details = [];
            if ($this->service->isDebug()) {
                $details['provider_status'] = $exception->getStatus();
                $details['provider_response'] = $exception->getBody();
            }

            return $this->responder
                ->error(
                    $response,
                    $traceId,
                    'bad_gateway',
                    'Falha ao remover contatos no WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while removing WhatsApp contacts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao remover contatos no WhatsApp.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $meta = [
            'page' => 1,
            'per_page' => count($phones),
            'count' => count($phones),
            'total' => count($phones),
            'extra' => array_filter([
                'source' => 'zapi',
                'provider_status' => $result['meta']['provider_status'] ?? null,
                'provider_body' => $result['meta']['provider_body'] ?? null,
            ], static fn ($value) => $value !== null),
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder
            ->successResource($response, (array) ($result['data'] ?? []), $traceId, $meta, $links)
            ->withHeader('X-Request-Id', $traceId);
    }

    private function normalizePayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        return array_values($payload);
    }

    /**
     * @param array<int, mixed> $payload
     * @return array<int, array{field:string, message:string}>
     */
    private function validate(array $payload): array
    {
        if ($payload === []) {
            return [['field' => 'contacts', 'message' => 'Informe ao menos um telefone.']];
        }

        $errors = [];

        foreach ($payload as $index => $item) {
            $phone = $this->sanitizePhone((string) $item);
            try {
                v::stringType()->digit()->length(10, 15)->setName('phone')->assert($phone);
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => sprintf('contacts[%d]', $index), 'message' => 'Telefone deve conter entre 10 e 15 digitos.'];
            }
        }

        return $errors;
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
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
