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

final class SendCallAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/call",
     *     tags={"WhatsApp"},
     *     summary="Inicia uma chamada pelo WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppCallPayload")),
     *     @OA\Response(response=200, description="Ligacao iniciada", @OA\JsonContent(ref="#/components/schemas/WhatsAppSendResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);
        $payload = $this->normalizePayload($request->getParsedBody());

        $validationErrors = $this->validate($payload);
        if ($validationErrors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $validationErrors)
                ->withHeader('X-Request-Id', $traceId);
        }

        $phone = $this->sanitizePhone((string) $payload['phone']);
        $duration = $this->normalizeCallDuration($payload['callDuration'] ?? null);

        try {
            $result = $this->service->sendCall($traceId, $phone, $duration);
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
                    'Falha ao iniciar ligacao via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp call', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao iniciar ligacao via WhatsApp.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = [
            'page' => 1,
            'per_page' => 1,
            'count' => 1,
            'total' => 1,
            'extra' => array_filter([
                'source' => 'zapi',
                'elapsed_ms' => $elapsedMs,
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
            ->successResource($response, $result['data'], $traceId, $meta, $links)
            ->withHeader('X-Request-Id', $traceId);
    }

    private function normalizePayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{field:string, message:string}>
     */
    private function validate(array $payload): array
    {
        $errors = [];

        $phone = $this->sanitizePhone((string) ($payload['phone'] ?? ''));
        try {
            v::stringType()->digit()->length(10, 15)->setName('phone')->assert($phone);
        } catch (NestedValidationException $exception) {
            $errors[] = ['field' => 'phone', 'message' => 'Telefone deve conter entre 10 e 15 digitos.'];
        }

        if (array_key_exists('callDuration', $payload) && $payload['callDuration'] !== null) {
            try {
                v::intVal()->between(1, 15)->setName('callDuration')->assert($payload['callDuration']);
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => 'callDuration', 'message' => 'callDuration deve estar entre 1 e 15 segundos.'];
            }
        }

        return $errors;
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizeCallDuration(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $intValue = (int) $value;

        return ($intValue >= 1 && $intValue <= 15) ? $intValue : null;
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
