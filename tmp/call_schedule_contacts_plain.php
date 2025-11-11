<?php

declare(strict_types=1);

use App\Settings\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
if (!@date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->useAttributes(true);

$settings = require $root . '/config/settings.php';
$containerBuilder->addDefinitions([
    Settings::class => static fn (): Settings => new Settings($settings),
    'settings' => $settings,
]);

($dependencies = require $root . '/config/dependencies.php')($containerBuilder);
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

($middleware = require $root . '/config/middleware.php')($app);
($routes = require $root . '/config/routes.php')($app);

$requestFactory = new ServerRequestFactory();
$request = $requestFactory
    ->createServerRequest('GET', '/v1/orders/schedule/contacts')
    ->withQueryParams([
        'schedule_start' => '2025-11-15 11:00:00',
        'schedule_end' => '2025-12-31 23:59:59',
    ])
    ->withHeader('Accept', 'text/plain');

$response = $app->handle($request);

fwrite(STDOUT, "Status: {$response->getStatusCode()}\n");
foreach ($response->getHeaders() as $name => $values) {
    fwrite(STDOUT, $name . ': ' . implode(', ', $values) . PHP_EOL);
}

fwrite(STDOUT, (string) $response->getBody() . PHP_EOL);
