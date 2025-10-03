<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Application\Services\WhatsAppService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\ZapiRequestException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;

// Limite Z-API: 10MB para v�deos de status
final class SendVideoStatusAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/status/video",
     *     tags={"WhatsApp"},
     *     summary="Envia v�deo no status",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppStatusVideoPayload")),
     *     @OA\Response(response=200, description="Status publicado", @OA\JsonContent(ref="#/components/schemas/WhatsAppSendResponse")),
     *     @OA\Response(response=422, description="Par�metros inv�lidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
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

        $video = trim((string) $payload['video']);
        $caption = array_key_exists('caption', $payload) ? $this->sanitizeCaption($payload['caption']) : null;

        try {
            $result = $this->service->sendVideoStatus($traceId, $video, $caption);
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
                    'Falha ao enviar v�deo de status via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp video status', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar v�deo de status.')
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
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private function validate(array $payload): array
    {
        $errors = [];

        $videoValue = $payload['video'] ?? null;
        try {
            v::stringType()->notEmpty()->setName('video')->assert($videoValue);
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'video',
                'message' => 'V�deo deve ser uma string n�o vazia.',
            ];
            $videoValue = null;
        }

        if (is_string($videoValue)) {
            $trimmedVideo = trim($videoValue);
            if (!$this->isValidVideoInput($trimmedVideo)) {
                $errors[] = [
                    'field' => 'video',
                    'message' => 'V�deo deve ser uma URL http(s) v�lida ou data URI.',
                ];
            }
        }

        if (array_key_exists('caption', $payload) && $payload['caption'] !== null) {
            try {
                v::stringType()->length(0, 3000)->setName('caption')->assert($payload['caption']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'caption',
                    'message' => 'Legenda deve possuir at� 3000 caracteres.',
                ];
            }
        }

        return $errors;
    }

    private function isValidVideoInput(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'data:video/')) {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    private function sanitizeCaption(mixed $caption): ?string
    {
        if ($caption === null) {
            return null;
        }

        if (!is_scalar($caption)) {
            return null;
        }

        $text = trim((string) $caption);

        return $text === '' ? null : $text;
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
