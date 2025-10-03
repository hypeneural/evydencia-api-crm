<?php

declare(strict_types=1);

$method = $argv[1] ?? 'GET';
$uri = $argv[2] ?? '/health';

$parsedUrl = parse_url($uri);
$path = $parsedUrl['path'] ?? '/';
$queryString = $parsedUrl['query'] ?? '';

$headers = [
    'REQUEST_METHOD' => strtoupper($method),
    'REQUEST_URI' => $path . ($queryString !== '' ? '?' . $queryString : ''),
    'QUERY_STRING' => $queryString,
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => 8080,
    'HTTP_HOST' => 'localhost:8080',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_API_KEY' => 'local-key',
    'REMOTE_ADDR' => '127.0.0.1',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];

$_SERVER = array_merge($_SERVER, $headers);

$_GET = [];
if ($queryString !== '') {
    parse_str($queryString, $_GET);
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $_SERVER['HTTP_CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'] = 'application/json';
}

require __DIR__ . '/public/index.php';
