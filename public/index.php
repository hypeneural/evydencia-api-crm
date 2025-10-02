<?php declare(strict_types=1);

use App\Settings\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
if (!@date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->useAttributes(true);

$settings = require __DIR__ . '/../config/settings.php';

$containerBuilder->addDefinitions([
    Settings::class => static fn (): Settings => new Settings($settings),
    'settings' => $settings,
]);

$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$middleware = require __DIR__ . '/../config/middleware.php';
$middleware($app);

$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

$app->run();
