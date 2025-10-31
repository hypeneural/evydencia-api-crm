<?php
require 'vendor/autoload.php';
use App\Application\Support\QueryMapper;
$mapper = new QueryMapper();
$options = $mapper->mapOrdersSearch([
    'order[session-start]' => '2025-09-01',
    'order[session-end]' => '2025-10-30',
    'product[slug]' => 'natal'
]);
var_export($options->crmQuery);
