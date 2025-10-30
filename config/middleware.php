<?php

declare(strict_types=1);

use App\Application\Support\ApiResponder;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\MetricsMiddleware;
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
    $app->add($container->get(MetricsMiddleware::class));

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
    $allowedOrigins = array_values(array_filter(array_map(
        static fn ($origin) => is_string($origin) ? trim($origin) : null,
        $allowedOrigins
    )));

    $allowAllOrigins = in_array('*', $allowedOrigins, true);
    if ($allowAllOrigins) {
        $allowedOrigins = array_values(array_filter(
            $allowedOrigins,
            static fn (string $origin): bool => $origin !== '*'
        ));
    }

    $allowLocalhost = (bool) ($corsSettings['allow_localhost'] ?? false);
    $hasWildcardOrigin = $allowAllOrigins;

    if ($allowLocalhost && !$hasWildcardOrigin) {
        $localhostOrigins = [
            'http://localhost',
            'https://localhost',
            'http://127.0.0.1',
            'https://127.0.0.1',
        ];

        $ports = $corsSettings['localhost_ports'] ?? [];
        foreach ($ports as $port) {
            $trimmed = trim((string) $port);
            if ($trimmed === '') {
                continue;
            }

            $localhostOrigins[] = sprintf('http://localhost:%s', $trimmed);
            $localhostOrigins[] = sprintf('https://localhost:%s', $trimmed);
            $localhostOrigins[] = sprintf('http://127.0.0.1:%s', $trimmed);
            $localhostOrigins[] = sprintf('https://127.0.0.1:%s', $trimmed);
        }

        $allowedOrigins = array_values(array_unique(array_merge($allowedOrigins, $localhostOrigins)));
    }

    $allowedMethods = $corsSettings['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    $allowedMethods = array_values(array_filter(array_map(
        static fn ($method) => is_string($method) ? strtoupper(trim($method)) : null,
        $allowedMethods
    )));
    $allowAllMethods = in_array('*', $allowedMethods, true);
    if ($allowAllMethods) {
        $allowedMethods = array_values(array_filter(
            $allowedMethods,
            static fn (string $method): bool => $method !== '*'
        ));
    }
    if ($allowedMethods === []) {
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    }

    $allowedHeaders = $corsSettings['allowed_headers'] ?? ['*'];
    $allowedHeaders = array_values(array_filter(array_map(
        static fn ($header) => is_string($header) ? trim($header) : null,
        $allowedHeaders
    )));
    $allowAllHeaders = (bool) ($corsSettings['allow_all_headers'] ?? true);
    if (in_array('*', $allowedHeaders, true)) {
        $allowAllHeaders = true;
        $allowedHeaders = array_values(array_filter(
            $allowedHeaders,
            static fn (string $header): bool => $header !== '*'
        ));
    }

    $exposedHeaders = $corsSettings['exposed_headers'] ?? ['Link', 'Trace-Id'];
    $exposedHeaders = array_values(array_filter(array_map(
        static fn ($header) => is_string($header) ? trim($header) : null,
        $exposedHeaders
    )));

    $corsOptions = [
        'allow_all_origins' => $allowAllOrigins,
        'allowed_origins' => $allowedOrigins,
        'allow_all_methods' => $allowAllMethods,
        'allowed_methods' => $allowedMethods,
        'allow_all_headers' => $allowAllHeaders,
        'allowed_headers' => $allowedHeaders,
        'exposed_headers' => $exposedHeaders,
        'allow_credentials' => (bool) ($corsSettings['allow_credentials'] ?? false),
        'max_age' => (int) ($corsSettings['max_age'] ?? 86400),
    ];

    $app->addRoutingMiddleware();
    $app->add(new CorsMiddleware($corsOptions));

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

