<?php

declare(strict_types=1);

use App\Application\Services\BlacklistService;
use App\Application\Services\CampaignService;
use App\Application\Services\LabelService;
use App\Application\Services\OrderService;
use App\Application\Services\ReportEngine;
use App\Application\Services\ReportService;
use App\Application\Services\ScheduledPostMediaService;
use App\Application\Services\ScheduledPostService;
use App\Application\Services\WhatsAppService;
use App\Application\Support\ApiResponder;
use App\Application\Support\CampaignSchedulePayloadNormalizer;
use App\Application\Support\QueryMapper;
use App\Middleware\OpenApiValidationMiddleware;
use App\Domain\Repositories\BlacklistRepositoryInterface;
use App\Domain\Repositories\OrderRepositoryInterface;
use App\Domain\Repositories\ScheduledPostRepositoryInterface;
use App\Infrastructure\Cache\RedisRateLimiter;
use App\Infrastructure\Cache\ScheduledPostCache;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Infrastructure\Http\ZapiClient;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\PdoBlacklistRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoScheduledPostRepository;
use App\Settings\Settings;
use DI\ContainerBuilder;
use GuzzleHttp\Client as HttpClient;
use Predis\Client as PredisClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use function DI\get;

return static function (ContainerBuilder $containerBuilder): void {
    $containerBuilder->addDefinitions([
        LoggerFactory::class => static function (ContainerInterface $container): LoggerFactory {
            return new LoggerFactory($container->get(Settings::class));
        },
        LoggerInterface::class => static function (ContainerInterface $container): LoggerInterface {
            /** @var LoggerFactory $factory */
            $factory = $container->get(LoggerFactory::class);

            return $factory->createLogger();
        },
        'db.connection' => static function (ContainerInterface $container): ?\PDO {
            $database = $container->get(Settings::class)->getDatabase();

            if (empty($database['host']) || empty($database['database'])) {
                return null;
            }

            $dsn = sprintf(
                '%s:host=%s;port=%d;dbname=%s;charset=%s',
                $database['driver'] ?? 'mysql',
                $database['host'],
                (int) ($database['port'] ?? 3306),
                $database['database'],
                $database['charset'] ?? 'utf8mb4'
            );

            $pdo = new \PDO($dsn, $database['username'] ?? 'root', $database['password'] ?? '', $database['options'] ?? []);
            $pdo->exec(sprintf('SET NAMES %s COLLATE %s', $database['charset'] ?? 'utf8mb4', $database['collation'] ?? 'utf8mb4_unicode_ci'));

            return $pdo;
        },
        PdoOrderRepository::class => static function (ContainerInterface $container): PdoOrderRepository {
            /** @var \PDO|null $pdo */
            $pdo = $container->get('db.connection');

            return new PdoOrderRepository($pdo);
        },
        OrderRepositoryInterface::class => get(PdoOrderRepository::class),
        PdoBlacklistRepository::class => static function (ContainerInterface $container): PdoBlacklistRepository {
            /** @var \PDO|null $pdo */
            $pdo = $container->get('db.connection');

            return new PdoBlacklistRepository($pdo);
        },
        BlacklistRepositoryInterface::class => get(PdoBlacklistRepository::class),
        PdoScheduledPostRepository::class => static function (ContainerInterface $container): PdoScheduledPostRepository {
            /** @var \PDO|null $pdo */
            $pdo = $container->get('db.connection');

            return new PdoScheduledPostRepository($pdo);
        },
        ScheduledPostRepositoryInterface::class => get(PdoScheduledPostRepository::class),
        'redis.client' => static function (ContainerInterface $container): ?PredisClient {
            $redis = $container->get(Settings::class)->getRedis();

            if (empty($redis['enabled'])) {
                return null;
            }

            $parameters = [
                'scheme' => 'tcp',
                'host' => $redis['host'] ?? '127.0.0.1',
                'port' => $redis['port'] ?? 6379,
                'timeout' => $redis['timeout'] ?? 1.5,
            ];

            if (!empty($redis['password'])) {
                $parameters['password'] = $redis['password'];
            }

            if (isset($redis['database'])) {
                $parameters['database'] = $redis['database'];
            }

            return new PredisClient($parameters);
        },
        RedisRateLimiter::class => static function (ContainerInterface $container): RedisRateLimiter {
            /** @var PredisClient|null $client */
            $client = $container->get('redis.client');

            return new RedisRateLimiter($client, $container->get(Settings::class));
        },
        ScheduledPostCache::class => static function (ContainerInterface $container): ScheduledPostCache {
            /** @var PredisClient|null $client */
            $client = $container->get('redis.client');

            return new ScheduledPostCache($client);
        },
        HttpClient::class => static function (ContainerInterface $container): HttpClient {
            $crm = $container->get(Settings::class)->getCrm();
            $baseUrl = $crm['base_url'] ?? 'https://evydencia.com/api';
            $baseUri = rtrim($baseUrl, '/') . '/';
            $timeout = $crm['timeout'] ?? 30.0;

            return new HttpClient([
                'base_uri' => $baseUri,
                'timeout' => $timeout,
                'http_errors' => false,
            ]);
        },
        QueryMapper::class => static function (): QueryMapper {
            return new QueryMapper();
        },
        ApiResponder::class => static function (ContainerInterface $container): ApiResponder {
            return new ApiResponder($container->get(Settings::class));
        },
        CampaignSchedulePayloadNormalizer::class => static function (ContainerInterface $container): CampaignSchedulePayloadNormalizer {
            return new CampaignSchedulePayloadNormalizer($container->get(Settings::class));
        },
        EvydenciaApiClient::class => static function (ContainerInterface $container): EvydenciaApiClient {
            return new EvydenciaApiClient(
                $container->get(HttpClient::class),
                $container->get(Settings::class),
                $container->get(LoggerInterface::class)
            );
        },
        ZapiClient::class => static function (ContainerInterface $container): ZapiClient {
            $settings = $container->get(Settings::class)->getZapi();

            $baseUrl = $settings['base_url'] ?? 'https://api.z-api.io';
            $instance = $settings['instance'] ?? '';
            $token = $settings['token'] ?? '';
            $clientToken = $settings['client_token'] ?? '';
            $timeout = isset($settings['timeout']) ? (float) $settings['timeout'] : 30.0;

            return new ZapiClient(
                $baseUrl,
                $instance,
                $token,
                $clientToken,
                $timeout,
                $container->get(LoggerInterface::class)
            );
        },
        BlacklistService::class => static function (ContainerInterface $container): BlacklistService {
            return new BlacklistService(
                $container->get(BlacklistRepositoryInterface::class),
                $container->get(LoggerInterface::class),
                $container->get('redis.client')
            );
        },
        ScheduledPostService::class => static function (ContainerInterface $container): ScheduledPostService {
            return new ScheduledPostService(
                $container->get(ScheduledPostRepositoryInterface::class),
                $container->get(ScheduledPostCache::class),
                $container->get(WhatsAppService::class),
                $container->get(LoggerInterface::class)
            );
        },
        ScheduledPostMediaService::class => static function (ContainerInterface $container): ScheduledPostMediaService {
            return new ScheduledPostMediaService(
                $container->get(Settings::class),
                $container->get(LoggerInterface::class)
            );
        },
        WhatsAppService::class => static function (ContainerInterface $container): WhatsAppService {
            $settings = $container->get(Settings::class);
            $app = $settings->getApp();
            $debug = (bool) ($app['debug'] ?? false);

            return new WhatsAppService(
                $container->get(ZapiClient::class),
                $container->get(LoggerInterface::class),
                $debug
            );
        },
        ReportEngine::class => static function (ContainerInterface $container): ReportEngine {
            return new ReportEngine(
                $container->get(EvydenciaApiClient::class),
                $container->get(QueryMapper::class),
                $container->get('redis.client'),
                $container->get(LoggerInterface::class),
                $container->get('db.connection'),
                __DIR__ . '/reports.php'
            );
        },
        ReportService::class => static function (ContainerInterface $container): ReportService {
            return new ReportService(
                $container->get(EvydenciaApiClient::class),
                $container->get(LoggerInterface::class)
            );
        },
        CampaignService::class => static function (ContainerInterface $container): CampaignService {
            return new CampaignService(
                $container->get(EvydenciaApiClient::class),
                $container->get(LoggerInterface::class)
            );
        },
        LabelService::class => static function (ContainerInterface $container): LabelService {
            return new LabelService(
                $container->get(Settings::class),
                $container->get(LoggerInterface::class),
                $container->get(EvydenciaApiClient::class)
            );
        },
        OrderService::class => static function (ContainerInterface $container): OrderService {
            return new OrderService(
                $container->get(EvydenciaApiClient::class),
                $container->get(OrderRepositoryInterface::class),
                $container->get(LoggerInterface::class)
            );
        },
        OpenApiValidationMiddleware::class => static function (ContainerInterface $container): OpenApiValidationMiddleware {

            $config = $container->get(Settings::class)->getOpenApi();

            $specPath = $config['spec_path'] ?? dirname(__DIR__) . '/public/openapi.json';

            $validateRequests = (bool) ($config['validate_requests'] ?? false);

            $validateResponses = (bool) ($config['validate_responses'] ?? false);



            return new OpenApiValidationMiddleware(

                $container->get(ApiResponder::class),

                $container->get(LoggerInterface::class),

                $specPath,

                $validateRequests,

                $validateResponses

            );

        },

    ]);
};







