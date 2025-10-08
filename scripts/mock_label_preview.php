<?php
declare(strict_types=1);

use App\Application\Services\LabelService;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Settings\Settings;
use Dotenv\Dotenv;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$settingsArray = require $rootPath . '/config/settings.php';
$settings = new Settings($settingsArray);
$logger = new NullLogger();

$labelsConfig = $settings->get('labels', []);
$mockLabels = is_array($labelsConfig) ? ($labelsConfig['mock_data'] ?? []) : [];

$mockOrder = [
    'id' => $mockLabels['id'] ?? 'MOCK-ORDER',
    'uuid' => 'mock-order-uuid',
    'customer' => [
        'name' => $mockLabels['nome_completo'] ?? 'Cliente Teste',
        'whatsapp' => $mockLabels['whats'] ?? '48999990000',
    ],
    'items' => [
        [
            'product' => [
                'name' => $mockLabels['pacote'] ?? 'Pacote Demonstração',
            ],
        ],
    ],
    'schedule_1' => '2025-12-24T15:00:00-03:00',
];

$mockResponse = new Response(
    200,
    ['Content-Type' => 'application/json'],
    (string) json_encode(['data' => $mockOrder], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$mockHandler = new MockHandler([$mockResponse]);
$handlerStack = HandlerStack::create($mockHandler);
$httpClient = new HttpClient([
    'handler' => $handlerStack,
    'base_uri' => $settings->get('crm')['base_url'] ?? 'https://example.com',
]);

$apiClient = new EvydenciaApiClient($httpClient, $settings, $logger);
$labelService = new LabelService($settings, $logger, $apiClient);

$orderId = $argv[1] ?? 'MOCK-ORDER';
$traceId = bin2hex(random_bytes(8));

$result = $labelService->generateLabel($orderId, $traceId);

echo PHP_EOL;
echo 'Etiqueta de teste gerada com sucesso!' . PHP_EOL;
echo 'Arquivo salvo em: ' . $result->absolutePath . PHP_EOL;
echo 'Dimensões (px): ' . $result->width . ' x ' . $result->height . PHP_EOL;
echo 'DPI: ' . $result->dpi . PHP_EOL;
echo 'Tamanho do arquivo: ' . $result->bytes . ' bytes' . PHP_EOL;
echo 'Trace ID utilizado: ' . $traceId . PHP_EOL;
echo PHP_EOL;
