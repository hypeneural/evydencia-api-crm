<?php
declare(strict_types=1);

use App\Settings\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
if (!@date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->useAttributes(true);

$settings = require __DIR__ . '/config/settings.php';

$containerBuilder->addDefinitions([
    Settings::class => static fn (): Settings => new Settings($settings),
    'settings' => $settings,
]);

$dependencies = require __DIR__ . '/config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$middleware = require __DIR__ . '/config/middleware.php';
$middleware($app);

$routes = require __DIR__ . '/config/routes.php';
$routes($app);

$requestFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();
$apiKey = $_ENV['APP_API_KEY'] ?? '';

function callEndpoint(string $label, string $method, string $path, array $options = []): array
{
    global $app, $requestFactory, $streamFactory, $apiKey;

    $query = $options['query'] ?? [];
    $body = $options['body'] ?? null;
    $headers = $options['headers'] ?? [];

    $request = $requestFactory->createServerRequest($method, $path);

    if ($query !== []) {
        $request = $request->withQueryParams($query);
    }

    $request = $request
        ->withHeader('Accept', 'application/json')
        ->withHeader('X-API-Key', $apiKey);

    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Falha ao codificar JSON para ' . $label . ': ' . json_last_error_msg());
        }

        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream($json));
    }

    $response = $app->handle($request);
    $payload = (string) $response->getBody();
    $jsonBody = null;

    if ($payload !== '') {
        $jsonBody = json_decode($payload, true);
    }

    echo "=== $label ===\n";
    echo 'Status: ' . $response->getStatusCode() . "\n";
    if ($jsonBody !== null) {
        $encoded = json_encode($jsonBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo ($encoded === false ? $payload : $encoded) . "\n";
    } else {
        echo $payload . "\n";
    }
    echo "\n";

    return [
        'status' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body_raw' => $payload,
        'body_json' => $jsonBody,
    ];
}

$cities = callEndpoint('GET /v1/cidades?includeBairros=true', 'GET', '/v1/cidades', [
    'query' => ['includeBairros' => 'true'],
]);

$cityId = $cities['body_json']['data'][0]['id'] ?? 1;
callEndpoint('GET /v1/cidades/{id}/bairros?withEscolas=true', 'GET', "/v1/cidades/$cityId/bairros", [
    'query' => ['withEscolas' => 'true'],
]);

$schoolsList = callEndpoint('GET /v1/escolas', 'GET', '/v1/escolas', [
    'query' => ['status' => 'todos'],
]);

$schoolData = $schoolsList['body_json']['data'][0] ?? null;
if ($schoolData === null) {
    fwrite(STDERR, "Nenhuma escola encontrada, abortando testes.\n");
    exit(1);
}

$schoolId = $schoolData['id'];
$originalVersion = (int) ($schoolData['versao_row'] ?? 1);
$originalObs = $schoolData['obs'] ?? '';
$originalPanfletagem = (bool) ($schoolData['panfletagem'] ?? false);

callEndpoint('GET /v1/escolas/{id}', 'GET', "/v1/escolas/$schoolId");

$patch1 = callEndpoint('PATCH /v1/escolas/{id} (toggle panfletagem)', 'PATCH', "/v1/escolas/$schoolId", [
    'headers' => ['If-Match' => (string) $originalVersion],
    'body' => [
        'panfletagem' => !$originalPanfletagem,
        'obs' => 'Atualizado via teste automatico',
        'versao_row' => $originalVersion,
    ],
]);
$newVersion = (int) ($patch1['body_json']['data']['versao_row'] ?? ($originalVersion + 1));

$patchRevert = callEndpoint('PATCH /v1/escolas/{id} (revert)', 'PATCH', "/v1/escolas/$schoolId", [
    'headers' => ['If-Match' => (string) $newVersion],
    'body' => [
        'panfletagem' => $originalPanfletagem,
        'obs' => $originalObs,
        'versao_row' => $newVersion,
    ],
]);
$versionAfterRevert = (int) ($patchRevert['body_json']['data']['versao_row'] ?? ($newVersion + 1));

$postObservation = callEndpoint('POST /v1/escolas/{id}/observacoes', 'POST', "/v1/escolas/$schoolId/observacoes", [
    'body' => [
        'observacao' => 'Observacao gerada em teste automatico.',
    ],
]);
$observationId = $postObservation['body_json']['data']['id'] ?? null;

callEndpoint('GET /v1/escolas/{id}/observacoes', 'GET', "/v1/escolas/$schoolId/observacoes");

if ($observationId !== null) {
    callEndpoint('DELETE /v1/escolas/{id}/observacoes/{obsId}', 'DELETE', "/v1/escolas/$schoolId/observacoes/$observationId");
}

$mutationPayload = [
    'client_id' => 'device-automated-test',
    'mutations' => [
        [
            'client_mutation_id' => uniqid('mut_', true),
            'type' => 'updateEscola',
            'escola_id' => $schoolId,
            'updates' => [
                'panfletagem' => !$originalPanfletagem,
                'versao_row' => $versionAfterRevert,
            ],
        ],
    ],
];
$syncMutation = callEndpoint('POST /v1/sync/mutations', 'POST', '/v1/sync/mutations', [
    'body' => $mutationPayload,
]);
$versionAfterMutation = (int) ($syncMutation['body_json']['mutations'][0]['versao_row'] ?? ($versionAfterRevert + 1));

callEndpoint('GET /v1/sync/changes?sinceVersion=0', 'GET', '/v1/sync/changes', [
    'query' => ['sinceVersion' => '0'],
]);

callEndpoint('PATCH /v1/escolas/{id} (final revert)', 'PATCH', "/v1/escolas/$schoolId", [
    'headers' => ['If-Match' => (string) $versionAfterMutation],
    'body' => [
        'panfletagem' => $originalPanfletagem,
        'obs' => $originalObs,
        'versao_row' => $versionAfterMutation,
    ],
]);

callEndpoint('GET /v1/escolas/{id} (final check)', 'GET', "/v1/escolas/$schoolId");
