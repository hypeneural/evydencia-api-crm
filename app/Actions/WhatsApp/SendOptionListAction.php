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

final class SendOptionListAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/option-list",
     *     tags={"WhatsApp"},
     *     summary="Envia lista de opcoes via WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppOptionListPayload")),
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
        $optionList = $this->formatOptionList($payload['optionList'] ?? []);
        $options = $this->extractOptions($payload);

        try {
            $result = $this->service->sendOptionList($traceId, $phone, $message, $optionList, $options);
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
                    'Falha ao enviar lista de opcoes via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp option list', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar lista de opcoes via WhatsApp.')
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

        $optionList = $payload['optionList'] ?? null;
        if (!is_array($optionList)) {
            $errors[] = [
                'field' => 'optionList',
                'message' => 'optionList deve ser um objeto.',
            ];
        } else {
            $title = trim((string) ($optionList['title'] ?? ''));
            try {
                v::stringType()->notEmpty()->length(1, 255)->setName('optionList.title')->assert($title);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'optionList.title',
                    'message' => 'Titulo da lista deve ser informado.',
                ];
            }

            $buttonLabel = trim((string) ($optionList['buttonLabel'] ?? ''));
            try {
                v::stringType()->notEmpty()->length(1, 255)->setName('optionList.buttonLabel')->assert($buttonLabel);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'optionList.buttonLabel',
                    'message' => 'buttonLabel deve ser informado.',
                ];
            }

            $optionsList = $optionList['options'] ?? null;
            if (!is_array($optionsList) || $optionsList === []) {
                $errors[] = [
                    'field' => 'optionList.options',
                    'message' => 'Informe ao menos uma opcao na lista.',
                ];
            } else {
                foreach ($optionsList as $index => $option) {
                    if (!is_array($option)) {
                        $errors[] = [
                            'field' => sprintf('optionList.options[%d]', $index),
                            'message' => 'Opcao deve ser um objeto.',
                        ];
                        continue;
                    }

                    $optionTitle = trim((string) ($option['title'] ?? ''));
                    try {
                        v::stringType()->notEmpty()->length(1, 255)->setName('option.title')->assert($optionTitle);
                    } catch (NestedValidationException $exception) {
                        $errors[] = [
                            'field' => sprintf('optionList.options[%d].title', $index),
                            'message' => 'Titulo da opcao deve ser informado.',
                        ];
                    }

                    if (array_key_exists('description', $option) && $option['description'] !== null) {
                        try {
                            v::stringType()->length(0, 1024)->setName('option.description')->assert($option['description']);
                        } catch (NestedValidationException $exception) {
                            $errors[] = [
                                'field' => sprintf('optionList.options[%d].description', $index),
                                'message' => 'Descricao da opcao deve possuir ate 1024 caracteres.',
                            ];
                        }
                    }

                    if (array_key_exists('id', $option) && $option['id'] !== null) {
                        try {
                            v::stringType()->length(1, 255)->setName('option.id')->assert($option['id']);
                        } catch (NestedValidationException $exception) {
                            $errors[] = [
                                'field' => sprintf('optionList.options[%d].id', $index),
                                'message' => 'id deve possuir ate 255 caracteres.',
                            ];
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
     * @param array<string, mixed> $optionList
     * @return array<string, mixed>
     */
    private function formatOptionList(array $optionList): array
    {
        $formatted = [
            'title' => trim((string) ($optionList['title'] ?? '')),
            'buttonLabel' => trim((string) ($optionList['buttonLabel'] ?? '')),
            'options' => [],
        ];

        if (isset($optionList['options']) && is_array($optionList['options'])) {
            foreach ($optionList['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $formattedOption = [
                    'title' => trim((string) ($option['title'] ?? '')),
                ];

                if (array_key_exists('description', $option)) {
                    $description = $this->sanitizeOptionalString($option['description']);
                    if ($description !== null) {
                        $formattedOption['description'] = $description;
                    }
                }

                if (array_key_exists('id', $option)) {
                    $id = $this->sanitizeOptionalString($option['id']);
                    if ($id !== null) {
                        $formattedOption['id'] = $id;
                    }
                }

                $formatted['options'][] = $formattedOption;
            }
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

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}

