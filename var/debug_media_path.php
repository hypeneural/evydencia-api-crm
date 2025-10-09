<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$containerBuilder = new DI\ContainerBuilder();
$containerBuilder->useAttributes(true);

$settings = require dirname(__DIR__) . '/config/settings.php';
$containerBuilder->addDefinitions([
    App\Settings\Settings::class => static fn (): App\Settings\Settings => new App\Settings\Settings($settings),
    'settings' => $settings,
]);

$dependencies = require dirname(__DIR__) . '/config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

/** @var App\Application\Services\ScheduledPostMediaService $service */
$service = $container->get(App\Application\Services\ScheduledPostMediaService::class);

$ref = new ReflectionClass($service);
$storagePathProp = $ref->getProperty('storagePath');
$storagePathProp->setAccessible(true);
$baseUrlProp = $ref->getProperty('baseUrl');
$baseUrlProp->setAccessible(true);

echo 'storagePath=' . $storagePathProp->getValue($service) . PHP_EOL;
echo 'baseUrl=' . $baseUrlProp->getValue($service) . PHP_EOL;
