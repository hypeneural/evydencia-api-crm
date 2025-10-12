<?php

declare(strict_types=1);

$appEnv = $_ENV['APP_ENV'] ?? 'production';

return [
    'app' => [
        'env' => $appEnv,
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
    'zapi' => [
        'base_url' => rtrim($_ENV['ZAPI_BASE_URL'] ?? 'https://api.z-api.io', '/'),
        'instance' => $_ENV['ZAPI_INSTANCE'] ?? '',
        'token' => $_ENV['ZAPI_TOKEN'] ?? '',
        'client_token' => $_ENV['ZAPI_CLIENT_TOKEN'] ?? '',
        'timeout' => isset($_ENV['ZAPI_TIMEOUT']) ? (float) $_ENV['ZAPI_TIMEOUT'] : 30.0,
    ],
    'media' => [
        'scheduled_posts' => [
            'storage_path' => $_ENV['SCHEDULED_POSTS_STORAGE_PATH'] ?? dirname(__DIR__) . '/public/status-media',
            'base_url' => rtrim($_ENV['SCHEDULED_POSTS_BASE_URL'] ?? (($_ENV['APP_URL'] ?? 'http://localhost') . '/status-media'), '/'),
            'image_max_bytes' => isset($_ENV['SCHEDULED_POSTS_IMAGE_MAX_SIZE_MB'])
                ? (int) $_ENV['SCHEDULED_POSTS_IMAGE_MAX_SIZE_MB'] * 1024 * 1024
                : 5 * 1024 * 1024,
            'video_max_bytes' => isset($_ENV['SCHEDULED_POSTS_VIDEO_MAX_SIZE_MB'])
                ? (int) $_ENV['SCHEDULED_POSTS_VIDEO_MAX_SIZE_MB'] * 1024 * 1024
                : 10 * 1024 * 1024,
            'image_mime_types' => array_filter(array_map('trim', explode(',', $_ENV['SCHEDULED_POSTS_IMAGE_MIME_TYPES'] ?? 'image/jpeg,image/png,image/gif,image/webp'))),
            'video_mime_types' => array_filter(array_map('trim', explode(',', $_ENV['SCHEDULED_POSTS_VIDEO_MIME_TYPES'] ?? 'video/mp4,video/mpeg,video/quicktime,video/x-msvideo'))),
        ],
    ],
    'labels' => [
        'dpi' => (int) ($_ENV['LABEL_DPI'] ?? 300),
        'width_mm' => (float) ($_ENV['LABEL_WIDTH_MM'] ?? 100),
        'height_mm' => (float) ($_ENV['LABEL_HEIGHT_MM'] ?? 40),
        'margins_mm' => [
            'top' => (float) ($_ENV['LABEL_MARGIN_TOP_MM'] ?? 0),
            'right' => (float) ($_ENV['LABEL_MARGIN_RIGHT_MM'] ?? 0),
            'bottom' => (float) ($_ENV['LABEL_MARGIN_BOTTOM_MM'] ?? 0),
            'left' => (float) ($_ENV['LABEL_MARGIN_LEFT_MM'] ?? 0),
        ],
        'assets_dir' => dirname(__DIR__) . '/public/etiqueta',
        'output_dir' => dirname(__DIR__) . '/public/etiqueta/out',
        'filename_pattern' => $_ENV['LABEL_FILENAME'] ?? 'etiqueta_{id}.png',
        'use_background' => filter_var($_ENV['LABEL_USE_BACKGROUND'] ?? 'true', FILTER_VALIDATE_BOOL),
        'background' => $_ENV['LABEL_BACKGROUND'] ?? 'background.jpg',
        'font' => $_ENV['LABEL_FONT'] ?? 'ArchivoBlack-Regular.ttf',
        'color_bg' => [0, 0, 0],
        'color_fg' => [255, 255, 255],
        'footer_url_enabled' => false,
        'layout' => [
            'nome_full' => ['x' => '11%', 'y' => '23%', 'size_pt' => 52, 'min_size_pt' => 20, 'max_w' => '60%', 'align' => 'left'],
            'primeiro' => ['x' => '83%', 'y' => '42%', 'size_pt' => 27, 'max_w' => '14%', 'align' => 'center'],
            'pacote' => ['x' => '12%', 'y' => '44%', 'size_pt' => 34, 'max_w' => '72%', 'align' => 'left'],
            'data' => ['x' => '11%', 'y' => '66%', 'size_pt' => 37, 'max_w' => '30%', 'align' => 'left'],
            'whats_ddd' => ['x' => '40%', 'y' => '66%', 'size_pt' => 20, 'max_w' => '7%', 'align' => 'left'],
            'whats_num' => ['x' => '45%', 'y' => '66%', 'size_pt' => 30, 'max_w' => '33%', 'align' => 'left'],
            'linha_url' => ['x' => '10%', 'y' => '88%', 'size_pt' => 43, 'max_w' => '50%', 'align' => 'left'],
            'qrcode' => ['size_px' => 240, 'x' => '83%', 'y' => '71%', 'margin_modules' => 0, 'ec_level' => 'H'],
        ],
        'url_template' => $_ENV['LABEL_URL_TEMPLATE'] ?? 'http://minhas.fotosdenatal.com/{id}',
        'copy_template' => $_ENV['LABEL_COPY_TEMPLATE'] ?? 'Acesse suas fotos',
        'mock_data' => [
            'nome_completo' => $_ENV['LABEL_MOCK_NOME_COMPLETO'] ?? 'Caroline T. De M. P. Dal Pias',
            'primeiro_nome' => $_ENV['LABEL_MOCK_PRIMEIRO_NOME'] ?? 'ANDERSON',
            'pacote' => $_ENV['LABEL_MOCK_PACOTE'] ?? 'Experiencia Entao e Natal',
            'data' => $_ENV['LABEL_MOCK_DATA'] ?? '25/09/25',
            'whats' => $_ENV['LABEL_MOCK_WHATS'] ?? '48996425287',
            'id' => $_ENV['LABEL_MOCK_ID'] ?? '1515',
        ],
    ],
    'cors' => [
        'allowed_origins' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*'))),
        'allowed_methods' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD'))),
        'allowed_headers' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_HEADERS'] ?? '*'))),
        'exposed_headers' => array_filter(array_map('trim', explode(',', $_ENV['CORS_EXPOSED_HEADERS'] ?? 'Link,X-RateLimit-Limit,X-RateLimit-Remaining,X-RateLimit-Reset,Trace-Id'))),
        'allow_credentials' => filter_var($_ENV['CORS_ALLOW_CREDENTIALS'] ?? false, FILTER_VALIDATE_BOOL),
        'allow_all_headers' => filter_var($_ENV['CORS_ALLOW_ALL_HEADERS'] ?? 'true', FILTER_VALIDATE_BOOL),
        'max_age' => (int) ($_ENV['CORS_MAX_AGE'] ?? 86400),
        'allow_localhost' => filter_var(
            $_ENV['CORS_ALLOW_LOCALHOST'] ?? ($appEnv !== 'production' ? 'true' : 'false'),
            FILTER_VALIDATE_BOOL
        ),
        'localhost_ports' => array_filter(array_map('trim', explode(',', $_ENV['CORS_LOCALHOST_PORTS'] ?? '3000,4200,5173,8080,8000'))),
    ],
    'openapi' => [
        'spec_path' => dirname(__DIR__) . '/public/openapi.json',
        'validate_requests' => filter_var(
            $_ENV['OPENAPI_VALIDATE_REQUESTS'] ?? (((($_ENV['APP_ENV'] ?? 'production') !== 'production') ? 'true' : 'false')),
            FILTER_VALIDATE_BOOL
        ),
        'validate_responses' => filter_var(
            $_ENV['OPENAPI_VALIDATE_RESPONSES'] ?? 'false',
            FILTER_VALIDATE_BOOL
        ),
    ],
    'security' => [
        'password_encryption_key' => $_ENV['PASSWORD_ENCRYPTION_KEY'] ?? '',
    ],
];
