<?php
require __DIR__ . '/../vendor/autoload.php';
$scanner = new OpenApi\Analysers\TokenScanner();
$details = $scanner->scanFile(__DIR__ . '/../app/OpenApi/OpenApiSpec.php');
var_dump($details);
