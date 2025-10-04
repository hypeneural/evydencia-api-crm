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

final class SendLinkAction
{
    private const ALLOWED_LINK_TYPES = ['SMALL', 'MEDIUM', 'LARGE'];

    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/link",
     *     tags={"WhatsApp"},
     *     summary="Envia mensagem com link enriquecido via WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppLinkPayload")),
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
        $message = trim((string) $payload['message']);
        $image = trim((string) $payload['image']);
        $linkUrl = trim((string) $payload['linkUrl']);
        $title = trim((string) $payload['title']);
        $description = trim((string) $payload['linkDescription']);
        $options = $this->extractOptions($payload);

        try {
            $result = $this->service->sendLink(
                $traceId,
                $phone,
                $message,
                $image,
                $linkUrl,
                $title,
                $description,
                $options
            );
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
                    'Falha ao enviar link via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp link', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar link via WhatsApp.')
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

        $requiredFields = [
            'message' => 'Mensagem deve ser informada.',
            'title' => 'Titulo deve ser informado.',
            'linkDescription' => 'Descricao do link deve ser informada.',
        ];

        foreach (['message', 'title', 'linkDescription'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            try {
                v::stringType()->notEmpty()->length(1, 4096)->setName($field)->assert($value);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => $field,
                    'message' => $requiredFields[$field] ?? sprintf('%s deve ser informado.', $field),
                ];
            }
        }

        foreach (['image', 'linkUrl'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            try {
                v::stringType()->notEmpty()->setName($field)->assert($value);
                if (!$this->isValidUrl($value)) {
                    throw new NestedValidationException();
                }
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => $field,
                    'message' => sprintf('%s deve ser uma URL http(s) valida.', $field),
                ];
            }
        }

        foreach (['delayMessage', 'delayTyping'] as $delayField) {
            if (array_key_exists($delayField, $payload)) {
                try {
                    v::intVal()->between(1, 15)->setName($delayField)->assert($payload[$delayField]);
                } catch (NestedValidationException $exception) {
                    $errors[] = [
                        'field' => $delayField,
                        'message' => 'Informe um numero entre 1 e 15.',
                    ];
                }
            }
        }

        if (array_key_exists('linkType', $payload) && $payload['linkType'] !== null) {
            $linkType = strtoupper((string) $payload['linkType']);
            if (!in_array($linkType, self::ALLOWED_LINK_TYPES, true)) {
                $errors[] = [
                    'field' => 'linkType',
                    'message' => 'linkType deve ser SMALL, MEDIUM ou LARGE.',
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

        if (array_key_exists('delayMessage', $payload)) {
            $options['delayMessage'] = (int) $payload['delayMessage'];
        }

        if (array_key_exists('delayTyping', $payload)) {
            $options['delayTyping'] = (int) $payload['delayTyping'];
        }

        if (array_key_exists('linkType', $payload)) {
            $normalized = $payload['linkType'];
            if (is_string($normalized) && $normalized !== '') {
                $options['linkType'] = strtoupper($normalized);
            }
        }

        return array_filter($options, static fn ($value) => $value !== null && $value !== '');
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function isValidUrl(string $value): bool
    {
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
