<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CheckPasswordAction
{
    public function __construct(
        private readonly PasswordService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/passwords/check",
     *     tags={"Passwords"},
     *     summary="Verifica se existe senha cadastrada para o par local/usuario",
     *     @OA\Parameter(name="local", in="query", required=true, description="Plataforma ou sistema", @OA\Schema(type="string")),
     *     @OA\Parameter(name="usuario", in="query", required=true, description="Usuario ou email", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Resultado da verificacao", @OA\JsonContent(ref="#/components/schemas/PasswordCheckResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $query = $request->getQueryParams();

        $local = isset($query['local']) ? trim((string) $query['local']) : '';
        $usuario = isset($query['usuario']) ? trim((string) $query['usuario']) : '';

        if ($local === '' || $usuario === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'local', 'message' => 'local obrigatorio.'],
                ['field' => 'usuario', 'message' => 'usuario obrigatorio.'],
            ]);
        }

        try {
            $result = $this->service->check($local, $usuario);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.check.failed', [
                'trace_id' => $traceId,
                'local' => $local,
                'usuario' => $usuario,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel verificar a credencial.');
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

    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
