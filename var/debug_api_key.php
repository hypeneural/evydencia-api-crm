<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$settings = require dirname(__DIR__) . '/config/settings.php';
$appSettings = (new App\Settings\Settings($settings))->getApp();

echo $appSettings['api_key'] ?? 'null', PHP_EOL;
