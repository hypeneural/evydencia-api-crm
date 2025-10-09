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

final class GetPasswordAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly PasswordService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/passwords/{id}",
     *     tags={"Passwords"},
     *     summary="Consulta detalhes completos de uma senha",
     *     @OA\Parameter(name="id", in="path", required=true, description="Identificador da senha (UUID)", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Senha encontrada", @OA\JsonContent(ref="#/components/schemas/PasswordResourceResponse")),
     *     @OA\Response(response=404, description="Senha nao encontrada", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
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

        $userId = $this->resolveUserId($request);
        $originIp = $this->resolveOriginIp($request);
        $userAgent = $this->resolveUserAgent($request);

        try {
            $resource = $this->service->get($id, true, $userId, $originIp, $userAgent);
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.get.failed', [
                'trace_id' => $traceId,
                'password_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel recuperar a senha solicitada.');
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

    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
