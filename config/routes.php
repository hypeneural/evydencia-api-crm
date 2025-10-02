<?php

declare(strict_types=1);

use App\Actions\Campaigns\GetCampaignScheduleAction;
use App\Actions\HealthCheckAction;
use App\Actions\Orders\GetOrderDetailAction;
use App\Actions\Orders\SearchOrdersAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Actions\Reports\GetSoldItemsAction;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/health', HealthCheckAction::class);

    $app->group('/v1', static function (RouteCollectorProxy $group): void {
        $group->get('/orders/search', SearchOrdersAction::class);
        $group->get('/orders/{uuid}', GetOrderDetailAction::class);
        $group->put('/orders/{uuid}/status', UpdateOrderStatusAction::class);

        $group->group('/reports', static function (RouteCollectorProxy $reports): void {
            $reports->get('/sold-items', GetSoldItemsAction::class);
        });

        $group->group('/campaigns', static function (RouteCollectorProxy $campaigns): void {
            $campaigns->get('/schedule', GetCampaignScheduleAction::class);
        });
    });
};
