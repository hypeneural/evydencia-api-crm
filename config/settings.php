<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
        'url' => rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/'),
        'api_key' => $_ENV['APP_API_KEY'] ?? null,
    ],
    'logger' => [
        'name' => $_ENV['LOG_CHANNEL'] ?? 'evy-api',
        'path' => $_ENV['LOG_PATH'] ?? dirname(__DIR__) . '/var/logs/app.log',
        'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_DATABASE'] ?? 'evy',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    'redis' => [
        'enabled' => !empty($_ENV['REDIS_HOST']),
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'password' => $_ENV['REDIS_PASSWORD'] ?? null,
        'database' => isset($_ENV['REDIS_DATABASE']) ? (int) $_ENV['REDIS_DATABASE'] : 0,
        'timeout' => isset($_ENV['REDIS_TIMEOUT']) ? (float) $_ENV['REDIS_TIMEOUT'] : 1.5,
    ],
    'rate_limit' => [
        'per_minute' => max(1, (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 60)),
        'window' => 60,
    ],
    'crm' => [
        'base_url' => rtrim($_ENV['CRM_BASE_URL'] ?? 'https://evydencia.com/api', '/'),
        'token' => $_ENV['CRM_TOKEN'] ?? '',
        'timeout' => isset($_ENV['CRM_TIMEOUT']) ? (float) $_ENV['CRM_TIMEOUT'] : 30.0,
    ],
    'cors' => [
        'allowed_origins' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*'))),
        'allowed_methods' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))),
        'allowed_headers' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Accept,Authorization,X-Requested-With,X-API-Key'))),
        'exposed_headers' => array_filter(array_map('trim', explode(',', $_ENV['CORS_EXPOSED_HEADERS'] ?? 'Link,X-RateLimit-Limit,X-RateLimit-Remaining,X-RateLimit-Reset,Trace-Id'))),
        'allow_credentials' => filter_var($_ENV['CORS_ALLOW_CREDENTIALS'] ?? false, FILTER_VALIDATE_BOOL),
        'max_age' => (int) ($_ENV['CORS_MAX_AGE'] ?? 86400),
    ],
];
