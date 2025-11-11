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
$start = new DateTimeImmutable('2025-11-15 11:00:00');
$requestedEnd = new DateTimeImmutable('2025-12-31 23:59:59');

$request = $requestFactory
    ->createServerRequest('GET', '/v1/orders/schedule/contacts')
    ->withQueryParams([
        'schedule_start' => $start->format('Y-m-d H:i:s'),
        'schedule_end' => $requestedEnd->format('Y-m-d H:i:s'),
    ])
    ->withHeader('Accept', 'text/plain');

$response = $app->handle($request);
$status = $response->getStatusCode();
$body = (string) $response->getBody();

if ($status !== 200) {
    fwrite(STDERR, sprintf("Falha (%d): %s\n", $status, $body));
    exit(1);
}

$lines = trim($body);
$targetDir = $root . '/tmp';
if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
    throw new RuntimeException(sprintf('Nao foi possivel criar o diretorio %s', $targetDir));
}

$targetFile = $targetDir . '/orders_schedule_contacts.txt';
file_put_contents($targetFile, $lines . PHP_EOL);

$count = $response->getHeaderLine('X-Total-Lines');
fwrite(STDOUT, sprintf("Arquivo gerado em %s (%s linhas)\n", $targetFile, $count !== '' ? $count : 'desconhecido'));
