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
use App\Actions\Monitoring\GetMetricsAction;
use App\Actions\Schools\CreateSchoolObservationAction;
use App\Actions\Schools\DeleteSchoolObservationAction;
use App\Actions\Schools\EnqueueSchoolSyncMutationsAction;
use App\Actions\Schools\FilterCitiesAction;
use App\Actions\Schools\FilterNeighborhoodsAction;
use App\Actions\Schools\FilterPeriodsAction;
use App\Actions\Schools\GetSchoolAction;
use App\Actions\Schools\GetSchoolKpiHistoricoAction;
use App\Actions\Schools\GetSchoolKpiOverviewAction;
use App\Actions\Schools\GetSchoolSyncChangesAction;
use App\Actions\Schools\ListCityAggregatesAction;
use App\Actions\Schools\ListSchoolObservationsAction;
use App\Actions\Schools\ListSchoolPanfletagemLogsAction;
use App\Actions\Schools\ListSchoolsAction;
use App\Actions\Schools\ListNeighborhoodAggregatesAction;
use App\Actions\Schools\UpdateSchoolAction;
use App\Actions\Schools\UpdateSchoolObservationAction;
use App\Actions\Events\CreateEventAction;
use App\Actions\Events\DeleteEventAction;
use App\Actions\Events\GetEventAction;
use App\Actions\Events\ListEventLogsAction;
use App\Actions\Events\ListEventsAction;
use App\Actions\Events\UpdateEventAction;
use App\Actions\Docs\ViewDocsAction;
use App\Actions\HealthCheckAction;
use App\Actions\Labels\GenerateOrderLabelAction;
use App\Actions\Leads\GetLeadsOverviewAction;
use App\Actions\Leads\ListLeadsAction;
use App\Actions\Orders\ExportOrderScheduleContactsAction;
use App\Actions\Orders\GetOrderDetailAction;
use App\Actions\Orders\GetOrderMediaStatusAction;
use App\Actions\Orders\SearchOrdersAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Actions\Reports\ExportReportAction;
use App\Actions\Reports\ListReportsAction;
use App\Actions\Reports\RunReportAction;
use App\Actions\Passwords\BulkPasswordsAction;
use App\Actions\Passwords\CheckPasswordAction;
use App\Actions\Passwords\CreatePasswordAction;
use App\Actions\Passwords\DeletePasswordAction;
use App\Actions\Passwords\ExportPasswordsAction;
use App\Actions\Passwords\GetPasswordAction;
use App\Actions\Passwords\GetPasswordPlatformsAction;
use App\Actions\Passwords\GetPasswordStatsAction;
use App\Actions\Passwords\ListPasswordsAction;
use App\Actions\Passwords\UpdatePasswordAction;
use App\Actions\ScheduledPosts\BulkDeleteScheduledPostsAction;
use App\Actions\ScheduledPosts\BulkDispatchScheduledPostsAction;
use App\Actions\ScheduledPosts\BulkUpdateScheduledPostsAction;
use App\Actions\ScheduledPosts\CreateScheduledPostAction;
use App\Actions\ScheduledPosts\DeleteScheduledPostAction;
use App\Actions\ScheduledPosts\DispatchScheduledPostsAction;
use App\Actions\ScheduledPosts\DuplicateScheduledPostAction;
use App\Actions\ScheduledPosts\GetScheduledPostAnalyticsAction;
use App\Actions\ScheduledPosts\GetReadyScheduledPostsAction;
use App\Actions\ScheduledPosts\GetScheduledPostAction;
use App\Actions\ScheduledPosts\ListScheduledPostsAction;
use App\Actions\ScheduledPosts\MarkScheduledPostSentAction;
use App\Actions\ScheduledPosts\UpdateScheduledPostAction;
use App\Actions\ScheduledPosts\UploadScheduledPostMediaAction;
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
    $app->get('/metrics', GetMetricsAction::class);

    $app->group('/v1', function (RouteCollectorProxy $group): void {
        $group->get('/cidades', ListCityAggregatesAction::class);
        $group->get('/cidades/{cidadeId:[0-9]+}/bairros', ListNeighborhoodAggregatesAction::class);

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
            $scheduled->get('/analytics', GetScheduledPostAnalyticsAction::class);
            $scheduled->post('', CreateScheduledPostAction::class);
            $scheduled->get('/ready', GetReadyScheduledPostsAction::class);
            $scheduled->post('/media/upload', UploadScheduledPostMediaAction::class);
            $scheduled->post('/worker/dispatch', DispatchScheduledPostsAction::class);
            $scheduled->delete('/bulk', BulkDeleteScheduledPostsAction::class);
            $scheduled->patch('/bulk', BulkUpdateScheduledPostsAction::class);
            $scheduled->post('/bulk/dispatch', BulkDispatchScheduledPostsAction::class);
            $scheduled->get('/{id:[0-9]+}', GetScheduledPostAction::class);
            $scheduled->map(['PUT', 'PATCH'], '/{id:[0-9]+}', UpdateScheduledPostAction::class);
            $scheduled->post('/{id:[0-9]+}/mark-sent', MarkScheduledPostSentAction::class);
            $scheduled->delete('/{id:[0-9]+}', DeleteScheduledPostAction::class);
            $scheduled->post('/{id:[0-9]+}/duplicate', DuplicateScheduledPostAction::class);
        });

        $group->group('/passwords', function (RouteCollectorProxy $passwords): void {
            $passwords->get('', ListPasswordsAction::class);
            $passwords->post('', CreatePasswordAction::class);
            $passwords->get('/stats', GetPasswordStatsAction::class);
            $passwords->get('/platforms', GetPasswordPlatformsAction::class);
            $passwords->get('/export', ExportPasswordsAction::class);
            $passwords->post('/bulk', BulkPasswordsAction::class);
            $passwords->get('/check', CheckPasswordAction::class);
            $passwords->get('/{id}', GetPasswordAction::class);
            $passwords->map(['PUT', 'PATCH'], '/{id}', UpdatePasswordAction::class);
            $passwords->delete('/{id}', DeletePasswordAction::class);
        });

        $group->get('/orders/media-status', GetOrderMediaStatusAction::class);
        $group->get('/orders/schedule/contacts', ExportOrderScheduleContactsAction::class);
        $group->get('/orders/search', SearchOrdersAction::class);
        $uuidPattern = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        $group->get("/orders/{uuid:{$uuidPattern}}", GetOrderDetailAction::class);
        $group->get("/orders/{uuid:{$uuidPattern}}/label", GenerateOrderLabelAction::class);
        $group->put("/orders/{uuid:{$uuidPattern}}/status", UpdateOrderStatusAction::class);

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

        $group->group('/leads', function (RouteCollectorProxy $leads): void {
            $leads->get('', ListLeadsAction::class);
            $leads->get('/overview', GetLeadsOverviewAction::class);
        });

        $group->group('/escolas', function (RouteCollectorProxy $schools): void {
            $schools->get('', ListSchoolsAction::class);
            $schools->get('/{id:[0-9]+}', GetSchoolAction::class);
            $schools->patch('/{id:[0-9]+}', UpdateSchoolAction::class);
            $schools->post('/{id:[0-9]+}/observacoes', CreateSchoolObservationAction::class);
            $schools->get('/{id:[0-9]+}/observacoes', ListSchoolObservationsAction::class);
            $schools->put('/{id:[0-9]+}/observacoes/{observacao_id:[0-9]+}', UpdateSchoolObservationAction::class);
            $schools->delete('/{id:[0-9]+}/observacoes/{observacao_id:[0-9]+}', DeleteSchoolObservationAction::class);
            $schools->get('/{id:[0-9]+}/panfletagem/logs', ListSchoolPanfletagemLogsAction::class);
        });

        $group->group('/filtros', function (RouteCollectorProxy $filters): void {
            $filters->get('/cidades', FilterCitiesAction::class);
            $filters->get('/bairros', FilterNeighborhoodsAction::class);
            $filters->get('/periodos', FilterPeriodsAction::class);
        });

        $group->group('/kpis', function (RouteCollectorProxy $kpis): void {
            $kpis->get('/overview', GetSchoolKpiOverviewAction::class);
            $kpis->get('/historico', GetSchoolKpiHistoricoAction::class);
        });

        $group->group('/sync', function (RouteCollectorProxy $sync): void {
            $sync->post('/mutations', EnqueueSchoolSyncMutationsAction::class);
            $sync->get('/changes', GetSchoolSyncChangesAction::class);
        });

        $group->group('/eventos', function (RouteCollectorProxy $events): void {
            $events->get('', ListEventsAction::class);
            $events->post('', CreateEventAction::class);
            $events->get('/{id:[0-9]+}', GetEventAction::class);
            $events->patch('/{id:[0-9]+}', UpdateEventAction::class);
            $events->delete('/{id:[0-9]+}', DeleteEventAction::class);
            $events->get('/{id:[0-9]+}/logs', ListEventLogsAction::class);
        });
    });
};




