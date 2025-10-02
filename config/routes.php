<?php

declare(strict_types=1);

use App\Actions\HealthCheckAction;
use App\Actions\Orders\SearchOrdersAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/health', HealthCheckAction::class);

    $app->group('/v1', static function (RouteCollectorProxy $group): void {
        $group->get('/orders/search', SearchOrdersAction::class);
        $group->put('/orders/{uuid}/status', UpdateOrderStatusAction::class);
    });
};
