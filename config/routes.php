<?php

declare(strict_types=1);

use App\Actions\Blacklist\CreateBlacklistEntryAction;
use App\Actions\Blacklist\DeleteBlacklistEntryAction;
use App\Actions\Blacklist\GetBlacklistEntryAction;
use App\Actions\Blacklist\ListBlacklistAction;
use App\Actions\Blacklist\SyncChristmasOrdersAction;
use App\Actions\Blacklist\UpdateBlacklistEntryAction;
use App\Actions\Campaigns\AbortScheduledCampaignAction;
use App\Actions\Campaigns\GetCampaignScheduleAction;
use App\Actions\Campaigns\ScheduleCampaignAction;
use App\Actions\Docs\ViewDocsAction;
use App\Actions\HealthCheckAction;
use App\Actions\Labels\GenerateOrderLabelAction;
use App\Actions\Orders\GetOrderDetailAction;
use App\Actions\Orders\SearchOrdersAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Actions\Reports\ExportReportAction;
use App\Actions\Reports\ListReportsAction;
use App\Actions\Reports\RunReportAction;
use App\Actions\ScheduledPosts\CreateScheduledPostAction;
use App\Actions\ScheduledPosts\DeleteScheduledPostAction;
use App\Actions\ScheduledPosts\GetReadyScheduledPostsAction;
use App\Actions\ScheduledPosts\GetScheduledPostAction;
use App\Actions\ScheduledPosts\ListScheduledPostsAction;
use App\Actions\ScheduledPosts\MarkScheduledPostSentAction;
use App\Actions\ScheduledPosts\UpdateScheduledPostAction;
use App\Actions\WhatsApp\AddChatTagAction;
use App\Actions\WhatsApp\AddContactsAction;
use App\Actions\WhatsApp\GetContactMetadataAction;
use App\Actions\WhatsApp\GetProfilePictureAction;
use App\Actions\WhatsApp\ListContactsAction;
use App\Actions\WhatsApp\PinMessageAction;
use App\Actions\WhatsApp\RemoveChatTagAction;
use App\Actions\WhatsApp\RemoveContactsAction;
use App\Actions\WhatsApp\SendAudioAction;
use App\Actions\WhatsApp\SendCallAction;
use App\Actions\WhatsApp\SendCarouselAction;
use App\Actions\WhatsApp\SendDocumentAction;
use App\Actions\WhatsApp\SendGifAction;
use App\Actions\WhatsApp\SendImageAction;
use App\Actions\WhatsApp\SendImageStatusAction;
use App\Actions\WhatsApp\SendLinkAction;
use App\Actions\WhatsApp\SendLocationAction;
use App\Actions\WhatsApp\SendOptionListAction;
use App\Actions\WhatsApp\SendPtvAction;
use App\Actions\WhatsApp\SendStickerAction;
use App\Actions\WhatsApp\SendTextAction;
use App\Actions\WhatsApp\SendTextStatusAction;
use App\Actions\WhatsApp\SendVideoStatusAction;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/doc', ViewDocsAction::class);
    $app->get('/health', HealthCheckAction::class);

    $app->group('/v1', function (RouteCollectorProxy $group): void {
        $group->group('/blacklist', function (RouteCollectorProxy $blacklist): void {
            $blacklist->get('', ListBlacklistAction::class);
            $blacklist->post('', CreateBlacklistEntryAction::class);
            $blacklist->get('/{id}', GetBlacklistEntryAction::class);
            $blacklist->map(['PUT', 'PATCH'], '/{id}', UpdateBlacklistEntryAction::class);
            $blacklist->delete('/{id}', DeleteBlacklistEntryAction::class);
            $blacklist->post('/christmas-orders/sync', SyncChristmasOrdersAction::class);
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
        $group->get('/orders/{uuid}/label', GenerateOrderLabelAction::class);
        $group->put('/orders/{uuid}/status', UpdateOrderStatusAction::class);

        $group->group('/whatsapp', function (RouteCollectorProxy $whatsapp): void {
            $whatsapp->post('/text', SendTextAction::class);
            $whatsapp->post('/audio', SendAudioAction::class);
            $whatsapp->post('/image', SendImageAction::class);
            $whatsapp->post('/document', SendDocumentAction::class);
            $whatsapp->post('/ptv', SendPtvAction::class);
            $whatsapp->post('/location', SendLocationAction::class);
            $whatsapp->post('/link', SendLinkAction::class);
            $whatsapp->post('/call', SendCallAction::class);
            $whatsapp->post('/sticker', SendStickerAction::class);
            $whatsapp->post('/gif', SendGifAction::class);
            $whatsapp->post('/carousel', SendCarouselAction::class);
            $whatsapp->post('/option-list', SendOptionListAction::class);
            $whatsapp->post('/message/pin', PinMessageAction::class);
            $whatsapp->get('/contacts', ListContactsAction::class);
            $whatsapp->post('/contacts/add', AddContactsAction::class);
            $whatsapp->delete('/contacts/remove', RemoveContactsAction::class);
            $whatsapp->get('/contacts/{phone}', GetContactMetadataAction::class);
            $whatsapp->put('/chats/{phone}/tags/{tag}/add', AddChatTagAction::class);
            $whatsapp->put('/chats/{phone}/tags/{tag}/remove', RemoveChatTagAction::class);
            $whatsapp->post('/status/image', SendImageStatusAction::class);
            $whatsapp->post('/status/video', SendVideoStatusAction::class);
            $whatsapp->post('/status/text', SendTextStatusAction::class);
            $whatsapp->get('/profile-picture', GetProfilePictureAction::class);
        });

        $group->group('/reports', function (RouteCollectorProxy $reports): void {
            $reports->get('', ListReportsAction::class);
            $reports->get('/{key}', RunReportAction::class);
            $reports->post('/{key}/export', ExportReportAction::class);
        });

        $group->group('/campaigns', function (RouteCollectorProxy $campaigns): void {
            $campaigns->get('/schedule', GetCampaignScheduleAction::class);
            $campaigns->post('/schedule/execute', ScheduleCampaignAction::class);
            $campaigns->post('/schedule/{id}/abort', AbortScheduledCampaignAction::class);
        });
    });
};




