<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Application\Support\ApiResponder;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use Throwable;

final class OpenApiValidationMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private ?ServerRequestValidator $requestValidator = null;
    private ?ResponseValidator $responseValidator = null;

    public function __construct(
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger,
        private readonly string $specPath,
        private readonly bool $validateRequests,
        private readonly bool $validateResponses
    ) {
        $this->enabled = $validateRequests || $validateResponses;

        if (!$this->enabled) {
            return;
        }

        if (!is_file($specPath)) {
            $this->logger->warning('OpenAPI validation habilitado, mas o arquivo da especificação não foi encontrado.', [
                'spec_path' => $specPath,
            ]);
            $this->enabled = false;

            return;
        }

        try {
            $builder = new ValidatorBuilder();
            $extension = strtolower((string) pathinfo($specPath, PATHINFO_EXTENSION));
            if (in_array($extension, ['yaml', 'yml'], true)) {
                $builder = $builder->fromYamlFile($specPath);
            } else {
                $builder = $builder->fromJsonFile($specPath);
            }

            if ($validateRequests) {
                $this->requestValidator = $builder->getServerRequestValidator();
            }

            if ($validateResponses) {
                $this->responseValidator = $builder->getResponseValidator();
            }
        } catch (Throwable $exception) {
            $this->logger->error('Falha ao inicializar o validador OpenAPI.', [
                'spec_path' => $specPath,
                'error' => $exception->getMessage(),
            ]);
            $this->enabled = false;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $traceId = $request->getAttribute('trace_id');
        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
            $request = $request->withAttribute('trace_id', $traceId);
        }

        if ($this->requestValidator !== null) {
            try {
                $this->requestValidator->validate($request);
            } catch (ValidationFailed $exception) {
                $this->logger->warning('OpenAPI request validation failed', [
                    'trace_id' => $traceId,
                    'path' => $request->getUri()->getPath(),
                    'details' => $exception->getMessage(),
                ]);

                $response = new Response(400);

                return $this->responder->error(
                    $response,
                    $traceId,
                    'invalid_request',
                    'Requisição não está em conformidade com a especificação OpenAPI.',
                    400,
                    ['details' => $exception->getMessage()]
                );
            } catch (Throwable $exception) {
                $this->logger->error('Erro inesperado ao validar requisição OpenAPI.', [
                    'trace_id' => $traceId,
                    'path' => $request->getUri()->getPath(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $response = $handler->handle($request);

        if ($this->responseValidator !== null) {
            try {
                $this->responseValidator->validate($request, $response);
            } catch (ValidationFailed $exception) {
                $this->logger->error('OpenAPI response validation failed', [
                    'trace_id' => $traceId,
                    'path' => $request->getUri()->getPath(),
                    'details' => $exception->getMessage(),
                ]);

                $base = new Response(500);

                return $this->responder->error(
                    $base,
                    $traceId,
                    'contract_violation',
                    'Resposta gerada não está aderente à especificação OpenAPI.',
                    500,
                    ['details' => $exception->getMessage()]
                );
            } catch (Throwable $exception) {
                $this->logger->error('Erro inesperado ao validar resposta OpenAPI.', [
                    'trace_id' => $traceId,
                    'path' => $request->getUri()->getPath(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $response;
    }
}

