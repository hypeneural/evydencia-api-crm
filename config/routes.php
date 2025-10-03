<?php

declare(strict_types=1);

use App\Actions\Blacklist\CreateBlacklistEntryAction;
use App\Actions\Blacklist\DeleteBlacklistEntryAction;
use App\Actions\Blacklist\GetBlacklistEntryAction;
use App\Actions\Blacklist\ListBlacklistAction;
use App\Actions\Blacklist\UpdateBlacklistEntryAction;
use App\Actions\Campaigns\GetCampaignScheduleAction;
use App\Actions\HealthCheckAction;
use App\Actions\Orders\GetOrderDetailAction;
use App\Actions\Orders\SearchOrdersAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Actions\Reports\GetSoldItemsAction;
use App\Actions\ScheduledPosts\CreateScheduledPostAction;
use App\Actions\ScheduledPosts\DeleteScheduledPostAction;
use App\Actions\ScheduledPosts\GetReadyScheduledPostsAction;
use App\Actions\ScheduledPosts\GetScheduledPostAction;
use App\Actions\ScheduledPosts\ListScheduledPostsAction;
use App\Actions\ScheduledPosts\MarkScheduledPostSentAction;
use App\Actions\ScheduledPosts\UpdateScheduledPostAction;
use App\Actions\WhatsApp\SendAudioAction;
use App\Actions\WhatsApp\SendDocumentAction;
use App\Actions\WhatsApp\SendImageAction;
use App\Actions\WhatsApp\SendImageStatusAction;
use App\Actions\WhatsApp\SendTextAction;
use App\Actions\WhatsApp\SendVideoStatusAction;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/health', HealthCheckAction::class);

    $app->group('/v1', function (RouteCollectorProxy $group): void {
        $group->group('/blacklist', function (RouteCollectorProxy $blacklist): void {
            $blacklist->get('', ListBlacklistAction::class);
            $blacklist->post('', CreateBlacklistEntryAction::class);
            $blacklist->get('/{id}', GetBlacklistEntryAction::class);
            $blacklist->map(['PUT', 'PATCH'], '/{id}', UpdateBlacklistEntryAction::class);
            $blacklist->delete('/{id}', DeleteBlacklistEntryAction::class);
        });

        $group->group('/scheduled-posts', function (RouteCollectorProxy $scheduled): void {
            $scheduled->get('', ListScheduledPostsAction::class);
            $scheduled->post('', CreateScheduledPostAction::class);
            $scheduled->get('/ready', GetReadyScheduledPostsAction::class);
            $scheduled->get('/{id}', GetScheduledPostAction::class);
            $scheduled->map(['PUT', 'PATCH'], '/{id}', UpdateScheduledPostAction::class);
            $scheduled->post('/{id}/mark-sent', MarkScheduledPostSentAction::class);
            $scheduled->delete('/{id}', DeleteScheduledPostAction::class);
        });

        $group->get('/orders/search', SearchOrdersAction::class);
        $group->get('/orders/{uuid}', GetOrderDetailAction::class);
        $group->put('/orders/{uuid}/status', UpdateOrderStatusAction::class);

        $group->group('/whatsapp', function (RouteCollectorProxy $whatsapp): void {
            $whatsapp->post('/text', SendTextAction::class);
            $whatsapp->post('/audio', SendAudioAction::class);
            $whatsapp->post('/image', SendImageAction::class);
            $whatsapp->post('/document', SendDocumentAction::class);
            $whatsapp->post('/status/image', SendImageStatusAction::class);
            $whatsapp->post('/status/video', SendVideoStatusAction::class);
        });

        $group->group('/reports', function (RouteCollectorProxy $reports): void {
            $reports->get('/sold-items', GetSoldItemsAction::class);
        });

        $group->group('/campaigns', function (RouteCollectorProxy $campaigns): void {
            $campaigns->get('/schedule', GetCampaignScheduleAction::class);
        });
    });
};
