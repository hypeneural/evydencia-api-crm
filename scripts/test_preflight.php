<?php

declare(strict_types=1);

use App\Settings\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$containerBuilder->useAttributes(true);

$settingsArray = require dirname(__DIR__) . '/config/settings.php';
$containerBuilder->addDefinitions([
    Settings::class => static fn (): Settings => new Settings($settingsArray),
    'settings' => $settingsArray,
]);

$dependencies = require dirname(__DIR__) . '/config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$middleware = require dirname(__DIR__) . '/config/middleware.php';
$middleware($app);

$routes = require dirname(__DIR__) . '/config/routes.php';
$routes($app);

$factory = new ServerRequestFactory();
$request = $factory->createServerRequest(
    'OPTIONS',
    'https://api.evydencia.com.br/v1/scheduled-posts',
    ['page' => '1', 'per_page' => '50']
)
    ->withHeader('Origin', 'https://gestao.fotosdenatal.com')
    ->withHeader('Access-Control-Request-Method', 'GET')
    ->withHeader('Access-Control-Request-Headers', 'Content-Type,Authorization');

$response = $app->handle($request);

$headers = [];
foreach ($response->getHeaders() as $name => $values) {
    $headers[$name] = $response->getHeaderLine($name);
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'headers' => $headers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
