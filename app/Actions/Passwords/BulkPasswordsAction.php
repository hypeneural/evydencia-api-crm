<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OpenApi\Annotations as OA;

final class BulkPasswordsAction
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
     *     path="/v1/passwords/bulk",
     *     tags={"Passwords"},
     *     summary="Executa acoes em massa sobre senhas",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/PasswordBulkPayload")),
     *     @OA\Response(response=200, description="Resultado da acao em massa", @OA\JsonContent(ref="#/components/schemas/PasswordBulkResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro ao processar a acao", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $action = isset($payload['action']) ? trim((string) $payload['action']) : '';
        $ids = isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : [];

        $userId = $this->resolveUserId($request);
        $originIp = $this->resolveOriginIp($request);
        $userAgent = $this->resolveUserAgent($request);

        try {
            $result = $this->service->bulkAction($action, $ids, $traceId, $userId, $originIp, $userAgent);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.bulk.failed', [
                'trace_id' => $traceId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel executar a acao em massa.');
        }

        $response = $this->responder->successResource(
            $response,
            $result,
            $traceId,
            ['source' => 'database'],
            ['self' => (string) $request->getUri()]
        );

        return $response->withHeader('X-Request-Id', $traceId);
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
