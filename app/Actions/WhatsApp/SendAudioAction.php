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



final class SendAudioAction

{

    public function __construct(

        private readonly WhatsAppService $service,

        private readonly ApiResponder $responder,

        private readonly LoggerInterface $logger

    ) {

    }



    /**

     * @OA\Post(

     *     path="/v1/whatsapp/audio",

     *     tags={"WhatsApp"},

     *     summary="Envia áudio pelo WhatsApp",

     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/WhatsAppAudioPayload")),

     *     @OA\Response(response=200, description="Mensagem aceita", @OA\JsonContent(ref="#/components/schemas/WhatsAppSendResponse")),

     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),

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

        $audio = trim((string) $payload['audio']);

        $options = $this->extractOptions($payload);



        try {

            $result = $this->service->sendAudio($traceId, $phone, $audio, $options);

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

                    'Falha ao enviar áudio via WhatsApp.',

                    502,

                    $details

                )

                ->withHeader('X-Request-Id', $traceId);

        } catch (RuntimeException $exception) {

            $this->logger->error('Unexpected error while sending WhatsApp audio', [

                'trace_id' => $traceId,

                'error' => $exception->getMessage(),

            ]);



            return $this->responder

                ->internalError($response, $traceId, 'Erro interno ao enviar áudio via WhatsApp.')

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



        $phone = $this->sanitizePhone((string) ($payload['phone'] ?? ''));

        try {

            v::stringType()->digit()->length(10, 15)->setName('phone')->assert($phone);

        } catch (NestedValidationException $exception) {

            $errors[] = [

                'field' => 'phone',

                'message' => 'Telefone deve conter entre 10 e 15 dígitos.',

            ];

        }



        $audioValue = trim((string) ($payload['audio'] ?? ''));

        try {

            v::stringType()->notEmpty()->setName('audio')->assert($audioValue);

            if (!$this->isValidAudioInput($audioValue)) {

                throw new NestedValidationException();

            }

        } catch (NestedValidationException $exception) {

            $errors[] = [

                'field' => 'audio',

                'message' => 'Áudio deve ser uma URL http(s) válida ou data URI.',

            ];

        }



        foreach (['delayMessage', 'delayTyping'] as $delayKey) {

            if (array_key_exists($delayKey, $payload)) {

                try {

                    v::intVal()->between(1, 15)->setName($delayKey)->assert($payload[$delayKey]);

                } catch (NestedValidationException $exception) {

                    $errors[] = [

                        'field' => $delayKey,

                        'message' => 'Informe um número entre 1 e 15.',

                    ];

                }

            }

        }



        foreach (['viewOnce', 'async', 'waveform'] as $booleanKey) {

            if (array_key_exists($booleanKey, $payload)) {

                if ($this->normalizeBoolean($payload[$booleanKey]) === null) {

                    $errors[] = [

                        'field' => $booleanKey,

                        'message' => 'Valor booleano inválido.',

                    ];

                }

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



        foreach (['viewOnce', 'async', 'waveform'] as $booleanKey) {

            if (array_key_exists($booleanKey, $payload)) {

                $options[$booleanKey] = $this->normalizeBoolean($payload[$booleanKey]);

            }

        }



        return array_filter($options, static fn ($value) => $value !== null);

    }



    private function normalizeBoolean(mixed $value): ?bool

    {

        if (is_bool($value)) {

            return $value;

        }



        if (is_string($value)) {

            $normalized = strtolower(trim($value));

            return match ($normalized) {

                '1', 'true', 'yes', 'on' => true,

                '0', 'false', 'no', 'off' => false,

                default => null,

            };

        }



        if (is_int($value) || is_float($value)) {

            $intValue = (int) $value;

            return match ($intValue) {

                1 => true,

                0 => false,

                default => null,

            };

        }



        return null;

    }



    private function sanitizePhone(string $phone): string

    {

        return preg_replace('/\D+/', '', $phone) ?? '';

    }



    private function isValidAudioInput(string $value): bool

    {

        if ($value === '') {

            return false;

        }



        if (str_starts_with($value, 'data:audio/')) {

            return true;

        }



        return filter_var($value, FILTER_VALIDATE_URL) !== false;

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

