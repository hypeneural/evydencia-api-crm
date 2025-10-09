<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OpenApi\Annotations as OA;

final class DeletePasswordAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly PasswordService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/passwords/{id}",
     *     tags={"Passwords"},
     *     summary="Remove uma senha (soft delete)",
     *     @OA\Parameter(name="id", in="path", required=true, description="Identificador da senha", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="Senha removida"),
     *     @OA\Response(response=404, description="Senha nao encontrada", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro ao excluir", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
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
            ])->withHeader('X-Request-Id', $traceId);
        }

        $userId = $this->resolveUserId($request);
        $originIp = $this->resolveOriginIp($request);
        $userAgent = $this->resolveUserAgent($request);

        try {
            $this->service->delete($id, $traceId, $userId, $originIp, $userAgent);
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage())
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.delete.failed', [
                'trace_id' => $traceId,
                'password_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel excluir a senha.')
                ->withHeader('X-Request-Id', $traceId);
        }

        return $response
            ->withStatus(204)
            ->withHeader('X-Request-Id', $traceId);
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
