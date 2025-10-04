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

final class SendGifAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/gif",
     *     tags={"WhatsApp"},
     *     summary="Envia GIF (mp4) via WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppGifPayload")),
     *     @OA\Response(response=200, description="Mensagem aceita", @OA\JsonContent(ref="#/components/schemas/WhatsAppSendResponse")),
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
        $gif = trim((string) $payload['gif']);
        $options = $this->extractOptions($payload);

        try {
            $result = $this->service->sendGif($traceId, $phone, $gif, $options);
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
                    'Falha ao enviar GIF via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp GIF', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar GIF via WhatsApp.')
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
            $errors[] = [
                'field' => 'phone',
                'message' => 'Telefone deve conter entre 10 e 15 digitos.',
            ];
        }

        $gifValue = trim((string) ($payload['gif'] ?? ''));
        try {
            v::stringType()->notEmpty()->setName('gif')->assert($gifValue);
            if (!$this->isValidVideoInput($gifValue)) {
                throw new NestedValidationException();
            }
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'gif',
                'message' => 'GIF deve ser uma URL http(s) valida ou data URI iniciando com data:video/.',
            ];
        }

        if (array_key_exists('messageId', $payload)) {
            try {
                v::stringType()->length(1, 255)->setName('messageId')->assert($payload['messageId']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'messageId',
                    'message' => 'messageId deve possuir ate 255 caracteres.',
                ];
            }
        }

        if (array_key_exists('delayMessage', $payload)) {
            try {
                v::intVal()->between(1, 15)->setName('delayMessage')->assert($payload['delayMessage']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'delayMessage',
                    'message' => 'Informe um numero entre 1 e 15.',
                ];
            }
        }

        if (array_key_exists('caption', $payload) && $payload['caption'] !== null) {
            try {
                v::stringType()->length(0, 3000)->setName('caption')->assert($payload['caption']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'caption',
                    'message' => 'caption deve possuir ate 3000 caracteres.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractOptions(array $payload): array
    {
        $options = [];

        if (array_key_exists('messageId', $payload)) {
            $options['messageId'] = $this->sanitizeOptionalString($payload['messageId']);
        }

        if (array_key_exists('delayMessage', $payload)) {
            $options['delayMessage'] = $payload['delayMessage'] !== null ? (int) $payload['delayMessage'] : null;
        }

        if (array_key_exists('caption', $payload)) {
            $options['caption'] = $this->sanitizeOptionalString($payload['caption']);
        }

        return array_filter($options, static fn ($value) => $value !== null && $value !== '');
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
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

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}

