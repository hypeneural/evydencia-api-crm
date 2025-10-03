<?php

declare(strict_types=1);

use App\Application\Support\ApiResponder;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RequestLoggingMiddleware;
use App\Middleware\OpenApiValidationMiddleware;
use App\Settings\Settings;
use Middlewares\TrailingSlash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;
use Tuupola\Middleware\CorsMiddleware;

return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container not available.');
    }

    $app->addBodyParsingMiddleware();
    $app->add(new TrailingSlash(false));
    $app->add($container->get(RateLimitMiddleware::class));
    $app->add($container->get(ApiKeyMiddleware::class));
    $app->add($container->get(RequestLoggingMiddleware::class));

    /** @var Settings $settings */
    $settings = $container->get(Settings::class);

    $openApiConfig = $settings->getOpenApi();
    if (($openApiConfig['validate_requests'] ?? false) || ($openApiConfig['validate_responses'] ?? false)) {
        $app->add($container->get(OpenApiValidationMiddleware::class));
    }
    $corsSettings = $settings->getCors();

    $allowedOrigins = $corsSettings['allowed_origins'] ?? ['*'];
    if ($allowedOrigins === []) {
        $allowedOrigins = ['*'];
    }

    $corsOptions = [
        'origin' => $allowedOrigins,
        'methods' => $corsSettings['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers.allow' => $corsSettings['allowed_headers'] ?? ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-API-Key'],
        'headers.expose' => $corsSettings['exposed_headers'] ?? ['Link', 'Trace-Id'],
        'credentials' => (bool) ($corsSettings['allow_credentials'] ?? false),
        'cache' => $corsSettings['max_age'] ?? 86400,
    ];

    $app->add(new CorsMiddleware($corsOptions));
    $app->addRoutingMiddleware();

    /** @var LoggerInterface $logger */
    $logger = $container->get(LoggerInterface::class);
    /** @var ApiResponder $responder */
    $responder = $container->get(ApiResponder::class);

    $errorMiddleware = $app->addErrorMiddleware(
        $settings->getApp()['debug'] ?? false,
        true,
        true,
        $logger
    );

    $errorMiddleware->setDefaultErrorHandler(
        function (
            ServerRequestInterface $request,
            Throwable $exception,
            bool $displayErrorDetails,
            bool $logErrors,
            bool $logErrorDetails
        ) use ($logger, $responder): ResponseInterface {
            $traceId = $request->getAttribute('trace_id');
            if (!is_string($traceId) || $traceId === '') {
                $traceId = bin2hex(random_bytes(8));
            }

            if ($logErrors) {
                $context = [
                    'trace_id' => $traceId,
                    'path' => $request->getUri()->getPath(),
                ];

                if ($logErrorDetails) {
                    $context['exception'] = $exception;
                }

                $logger->error('Unhandled exception', $context);
            }

            $statusCode = 500;
            $detail = 'Internal Server Error';
            if ($exception instanceof HttpException) {
                $statusCode = $exception->getCode();
                if ($statusCode < 400 || $statusCode > 599) {
                    $statusCode = 500;
                }
                $detail = $exception->getMessage();
            } elseif ($displayErrorDetails) {
                $detail = $exception->getMessage();
            }

            $baseResponse = new Response($statusCode);

            return match ($statusCode) {
                401 => $responder->unauthorized($baseResponse, $traceId, $detail),
                429 => $responder->tooManyRequests($baseResponse, $traceId, $detail, 60),
                502 => $responder->badGateway($baseResponse, $traceId, $detail),
                500 => $responder->internalError($baseResponse, $traceId, $detail),
                default => $responder->error($baseResponse, $traceId, 'error', $detail, $statusCode),
            };
        }
    );
};

