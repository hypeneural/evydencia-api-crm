<?php
require __DIR__ . '/../vendor/autoload.php';
$openapi = OpenApi\Generator::scan(['tmp']);
var_dump($openapi->info);
