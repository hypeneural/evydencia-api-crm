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

$baseQuery = [
    'product[slug]' => 'natal',
    'order[session-start]' => (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
    'order[session-end]' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
    'include' => 'items,customer,status',
    'page' => 1,
    'per_page' => 5,
];

$statusesToTest = [
    'session_schedule',
    'selection_schedule_confirmed',
    'selection_finalized',
    '6',
    '9',
    'waiting_product_retrieve',
];

$results = [];

foreach ($statusesToTest as $status) {
    $query = $baseQuery;
    $query['order[status]'] = $status;

    try {
        $response = $client->searchOrders($query, $traceId);
        $body = $response['body'] ?? [];
        $first = $body['data'][0] ?? null;
        $firstStatus = null;
        if (is_array($first)) {
            $statusDetails = $first['status'] ?? null;
            if (is_array($statusDetails)) {
                $firstStatus = [
                    'id' => $statusDetails['id'] ?? null,
                    'name' => $statusDetails['name'] ?? null,
                    'slug' => $statusDetails['slug'] ?? ($statusDetails['code'] ?? null),
                ];
            }
        }
        $results[] = [
            'status_filter' => $status,
            'http_status' => $response['status'],
            'count' => isset($body['meta']['count']) ? (int) $body['meta']['count'] : count($body['data'] ?? []),
            'first_status' => $firstStatus,
        ];
    } catch (CrmRequestException $exception) {
        $results[] = [
            'status_filter' => $status,
            'http_status' => $exception->getStatusCode(),
            'error' => $exception->getPayload(),
        ];
    }
}

echo json_encode(
    [
        'query' => $baseQuery,
        'results' => $results,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
