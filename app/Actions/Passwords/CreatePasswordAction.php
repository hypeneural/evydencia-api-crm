<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CreatePasswordAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly PasswordService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/passwords",
     *     tags={"Passwords"},
     *     summary="Cria uma nova senha",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/PasswordCreatePayload")),
     *     @OA\Response(response=201, description="Senha criada", @OA\JsonContent(ref="#/components/schemas/PasswordResourceResponse")),
     *     @OA\Response(response=422, description="Dados invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro ao criar a senha", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $userId = $this->resolveUserId($request);
        $originIp = $this->resolveOriginIp($request);
        $userAgent = $this->resolveUserAgent($request);

        try {
            $resource = $this->service->create($payload, $traceId, $userId, $originIp, $userAgent);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.create.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel cadastrar a senha.');
        }

        $location = (string) $request->getUri()->withPath($request->getUri()->getPath() . '/' . $resource['id']);

        $response = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database'],
            ['self' => $location]
        );

        return $response
            ->withStatus(201)
            ->withHeader('Location', $location)
            ->withHeader('X-Request-Id', $traceId);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $input): array
    {
        return is_array($input) ? $input : [];
    }

    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
