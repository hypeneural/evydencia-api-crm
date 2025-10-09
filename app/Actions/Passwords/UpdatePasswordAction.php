<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class UpdatePasswordAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly PasswordService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Patch(
     *     path="/v1/passwords/{id}",
     *     tags={"Passwords"},
     *     summary="Atualiza informacoes de uma senha",
     *     @OA\Parameter(name="id", in="path", required=true, description="Identificador da senha", @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/PasswordUpdatePayload")),
     *     @OA\Response(response=200, description="Senha atualizada", @OA\JsonContent(ref="#/components/schemas/PasswordResourceResponse")),
     *     @OA\Response(response=404, description="Senha nao encontrada", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=422, description="Dados invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro ao atualizar", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $id = isset($args['id']) ? trim((string) $args['id']) : '';

        if ($id === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'id', 'message' => 'Identificador obrigatorio.'],
            ]);
        }

        $payload = $this->normalizePayload($request->getParsedBody());
        $userId = $this->resolveUserId($request);
        $originIp = $this->resolveOriginIp($request);
        $userAgent = $this->resolveUserAgent($request);

        try {
            $resource = $this->service->update($id, $payload, $traceId, $userId, $originIp, $userAgent);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.update.failed', [
                'trace_id' => $traceId,
                'password_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar a senha.');
        }

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        $response = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database'],
            $links
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
