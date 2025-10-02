<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Settings\Settings;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function createLogger(?string $channel = null): LoggerInterface
    {
        $loggerSettings = $this->settings->getLogger();
        $channelName = $channel ?? ($loggerSettings['name'] ?? 'evy-api');
        $path = $loggerSettings['path'] ?? dirname(__DIR__, 3) . '/var/logs/app.log';
        $level = Logger::toMonologLevel($loggerSettings['level'] ?? 'debug');

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handler = new StreamHandler($path, $level, true, 0644);

        $logger = new Logger($channelName);
        $logger->pushHandler($handler);
        $logger->pushProcessor(new UidProcessor());
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}

