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

require __DIR__ . '/../vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$settingsArray = require $rootPath . '/config/settings.php';
$settings = new Settings($settingsArray);
$logger = new NullLogger();

$labelsConfig = $settings->get('labels', []);
$mockLabels = is_array($labelsConfig) ? ($labelsConfig['mock_data'] ?? []) : [];

$orderId = valueFromRequest('order_id', $mockLabels['id'] ?? 'MOCK-ORDER');
$customerName = valueFromRequest('name', $mockLabels['nome_completo'] ?? 'Cliente Teste');
$package = valueFromRequest('package', $mockLabels['pacote'] ?? 'Pacote Demonstração');
$scheduleInput = valueFromRequest('date', $mockLabels['data'] ?? '25/12/25');
$whatsapp = valueFromRequest('whats', $mockLabels['whats'] ?? '48999990000');
$uuid = valueFromRequest('uuid', $orderId . '-uuid');

$scheduleIso = resolveScheduleIso($scheduleInput);

$mockOrder = [
    'id' => $orderId,
    'uuid' => $uuid,
    'customer' => [
        'name' => $customerName,
        'whatsapp' => $whatsapp,
    ],
    'items' => [
        [
            'product' => [
                'name' => $package,
            ],
        ],
    ],
    'schedule_1' => $scheduleIso,
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

$traceId = bin2hex(random_bytes(8));

try {
    $result = $labelService->generateLabel($orderId, $traceId);
    $stream = $result->stream;
    $stream->rewind();
    $binary = $stream->getContents();

    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($binary));
    header('X-Trace-Id: ' . $traceId);
    header('Content-Disposition: inline; filename="mock-label.png"');
    header('Cache-Control: no-store, max-age=0');

    echo $binary;
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'label_generation_failed',
            'message' => $exception->getMessage(),
        ],
        'trace_id' => $traceId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @return string
 */
function valueFromRequest(string $key, string $default): string
{
    $value = $_GET[$key] ?? null;
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return $default;
}

/**
 * @return string
 */
function resolveScheduleIso(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    try {
        if (preg_match('/^\d{2}\/\d{2}\/\d{2,4}$/', $input) === 1) {
            $format = strlen($input) === 8 ? 'd/m/y' : 'd/m/Y';
            $date = \DateTimeImmutable::createFromFormat($format, $input);
            if ($date instanceof \DateTimeImmutable) {
                return $date->setTime(15, 0)->format(DATE_ATOM);
            }
        }

        $date = new \DateTimeImmutable($input);
        return $date->format(DATE_ATOM);
    } catch (\Throwable) {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
