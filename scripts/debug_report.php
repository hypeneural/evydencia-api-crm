<?php

declare(strict_types=1);

use App\Domain\Exception\CrmRequestException;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Settings\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;

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

/** @var EvydenciaApiClient $client */
$client = $container->get(EvydenciaApiClient::class);

$traceId = bin2hex(random_bytes(8));

$crmQuery = [
    'product[slug]' => 'natal',
    'include' => 'items,customer,status',
    'order[session-end]' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'order[status]' => 'waiting_product_retrieve',
    'page' => 1,
    'per_page' => 200,
];

try {
    $response = $client->searchOrders($crmQuery, $traceId);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (CrmRequestException $exception) {
    echo 'CRM STATUS: ' . $exception->getStatusCode() . PHP_EOL;
    echo json_encode($exception->getPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
