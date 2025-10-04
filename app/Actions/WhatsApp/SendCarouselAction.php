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

final class SendCarouselAction
{
    private const BUTTON_TYPES = ['CALL', 'URL', 'REPLY'];

    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/carousel",
     *     tags={"WhatsApp"},
     *     summary="Envia mensagem em carrosel via WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppCarouselPayload")),
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
        $carousel = $this->formatCarousel($payload['carousel'] ?? []);
        $options = $this->extractOptions($payload);

        try {
            $result = $this->service->sendCarousel($traceId, $phone, $message, $carousel, $options);
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
                    'Falha ao enviar carrosel via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp carousel', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar carrosel via WhatsApp.')
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

        $messageValue = trim((string) ($payload['message'] ?? ''));
        try {
            v::stringType()->notEmpty()->length(1, 4096)->setName('message')->assert($messageValue);
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'message',
                'message' => 'Mensagem deve ser informada.',
            ];
        }

        $carousel = $payload['carousel'] ?? null;
        if (!is_array($carousel) || $carousel === []) {
            $errors[] = [
                'field' => 'carousel',
                'message' => 'Informe ao menos um cartao para o carrosel.',
            ];
        } else {
            foreach ($carousel as $index => $card) {
                if (!is_array($card)) {
                    $errors[] = [
                        'field' => sprintf('carousel[%d]', $index),
                        'message' => 'Cartao deve ser um objeto.',
                    ];
                    continue;
                }

                $text = trim((string) ($card['text'] ?? ''));
                try {
                    v::stringType()->notEmpty()->length(1, 4096)->setName('carousel.text')->assert($text);
                } catch (NestedValidationException $exception) {
                    $errors[] = [
                        'field' => sprintf('carousel[%d].text', $index),
                        'message' => 'Texto do cartao deve ser informado.',
                    ];
                }

                $image = trim((string) ($card['image'] ?? ''));
                try {
                    v::stringType()->notEmpty()->setName('carousel.image')->assert($image);
                    if (!$this->isValidUrl($image)) {
                        throw new NestedValidationException();
                    }
                } catch (NestedValidationException $exception) {
                    $errors[] = [
                        'field' => sprintf('carousel[%d].image', $index),
                        'message' => 'Imagem do cartao deve ser uma URL http(s) valida.',
                    ];
                }

                if (array_key_exists('buttons', $card)) {
                    $buttons = $card['buttons'];
                    if (!is_array($buttons)) {
                        $errors[] = [
                            'field' => sprintf('carousel[%d].buttons', $index),
                            'message' => 'buttons deve ser uma lista de objetos.',
                        ];
                    } else {
                        foreach ($buttons as $buttonIndex => $button) {
                            if (!is_array($button)) {
                                $errors[] = [
                                    'field' => sprintf('carousel[%d].buttons[%d]', $index, $buttonIndex),
                                    'message' => 'Botao deve ser um objeto.',
                                ];
                                continue;
                            }

                            $type = strtoupper(trim((string) ($button['type'] ?? '')));
                            if (!in_array($type, self::BUTTON_TYPES, true)) {
                                $errors[] = [
                                    'field' => sprintf('carousel[%d].buttons[%d].type', $index, $buttonIndex),
                                    'message' => 'type deve ser CALL, URL ou REPLY.',
                                ];
                            }

                            $label = trim((string) ($button['label'] ?? ''));
                            try {
                                v::stringType()->notEmpty()->length(1, 256)->setName('button.label')->assert($label);
                            } catch (NestedValidationException $exception) {
                                $errors[] = [
                                    'field' => sprintf('carousel[%d].buttons[%d].label', $index, $buttonIndex),
                                    'message' => 'label deve ser informado.',
                                ];
                            }

                            if ($type === 'CALL') {
                                $buttonPhone = $this->sanitizePhone((string) ($button['phone'] ?? ''));
                                try {
                                    v::stringType()->digit()->length(10, 15)->setName('button.phone')->assert($buttonPhone);
                                } catch (NestedValidationException $exception) {
                                    $errors[] = [
                                        'field' => sprintf('carousel[%d].buttons[%d].phone', $index, $buttonIndex),
                                        'message' => 'phone deve conter entre 10 e 15 digitos para botao CALL.',
                                    ];
                                }
                            }

                            if ($type === 'URL') {
                                $url = trim((string) ($button['url'] ?? ''));
                                try {
                                    v::stringType()->notEmpty()->setName('button.url')->assert($url);
                                    if (!$this->isValidUrl($url)) {
                                        throw new NestedValidationException();
                                    }
                                } catch (NestedValidationException $exception) {
                                    $errors[] = [
                                        'field' => sprintf('carousel[%d].buttons[%d].url', $index, $buttonIndex),
                                        'message' => 'url deve ser uma URL http(s) valida.',
                                    ];
                                }
                            }
                        }
                    }
                }
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
            $options['delayMessage'] = $payload['delayMessage'] !== null ? (int) $payload['delayMessage'] : null;
        }

        return array_filter($options, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<int, mixed> $carousel
     * @return array<int, array<string, mixed>>
     */
    private function formatCarousel(array $carousel): array
    {
        $formatted = [];

        foreach ($carousel as $card) {
            if (!is_array($card)) {
                continue;
            }

            $formattedCard = [
                'text' => trim((string) ($card['text'] ?? '')),
                'image' => trim((string) ($card['image'] ?? '')),
            ];

            if (array_key_exists('buttons', $card) && is_array($card['buttons'])) {
                $buttons = [];
                foreach ($card['buttons'] as $button) {
                    if (!is_array($button)) {
                        continue;
                    }

                    $formattedButton = [
                        'type' => strtoupper(trim((string) ($button['type'] ?? ''))),
                        'label' => trim((string) ($button['label'] ?? '')),
                    ];

                    if (array_key_exists('id', $button)) {
                        $id = $this->sanitizeOptionalString($button['id']);
                        if ($id !== null) {
                            $formattedButton['id'] = $id;
                        }
                    }

                    if (array_key_exists('phone', $button)) {
                        $phone = $this->sanitizePhone((string) $button['phone']);
                        if ($phone !== '') {
                            $formattedButton['phone'] = $phone;
                        }
                    }

                    if (array_key_exists('url', $button)) {
                        $url = $this->sanitizeOptionalString($button['url']);
                        if ($url !== null) {
                            $formattedButton['url'] = $url;
                        }
                    }

                    $buttons[] = array_filter(
                        $formattedButton,
                        static fn ($value) => $value !== null && $value !== ''
                    );
                }

                if ($buttons !== []) {
                    $formattedCard['buttons'] = $buttons;
                }
            }

            $formatted[] = $formattedCard;
        }

        return $formatted;
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

