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
$now = new DateTimeImmutable('now');
$startDate = new DateTimeImmutable('2025-11-15');
$endBoundary = $now < $startDate ? $startDate : $now;

$query = [
    'order[session-start]' => '2025-11-15',
    'order[session-end]' => $endBoundary->format('Y-m-d'),
    'per_page' => 50,
    'page' => 1,
];

$request = $requestFactory
    ->createServerRequest('GET', '/v1/orders/search')
    ->withQueryParams($query)
    ->withHeader('Accept', 'application/json');

$response = $app->handle($request);

fwrite(STDOUT, "Status: {$response->getStatusCode()}\n");
foreach ($response->getHeaders() as $name => $values) {
    fwrite(STDOUT, $name . ': ' . implode(', ', $values) . PHP_EOL);
}

$body = (string) $response->getBody();
$decoded = json_decode($body, true);
if (is_array($decoded)) {
    $body = json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

fwrite(STDOUT, $body . PHP_EOL);
