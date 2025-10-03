<?php

declare(strict_types=1);

use App\Application\Reports\ReportResult;
use App\Application\Reports\FinalizedLateSelectionsReport;
use App\Application\Reports\NotClosedOrdersReport;
use App\Application\Reports\OrdersWithoutParticipantsReport;
use App\Application\Reports\ParticipantsUnder8Report;
use App\Application\Reports\PresaleVsCurrentReport;
use App\Infrastructure\Http\EvydenciaApiClient;
use Respect\Validation\Validator as v;

return [
    'orders.missing_schedule' => [
        'type' => 'closure',
        'title' => 'Pedidos com pagamento confirmado e sem agendamento',
        'cache' => 900,
        'rules' => [
            'product[slug]' => v::optional(v::stringType()->length(1, 64)),
            'order[created-start]' => v::optional(v::date('Y-m-d')),
            'order[created-end]' => v::optional(v::date('Y-m-d')),
        ],
        'defaults' => [
            'order[status]' => 'payment_confirmed',
            'order[created-start]' => (new DateTimeImmutable('-90 days'))->format('Y-m-d'),
            'order[created-end]' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'include' => 'items,customer',
        ],
        'columns' => ['uuid', 'customer_name', 'customer_whatsapp', 'product', 'created_at'],
        'sortable' => ['created_at', 'customer_name', 'product'],
        'runner' => static function (EvydenciaApiClient $api, array $filters, string $traceId): ReportResult {
            $response = $api->searchOrders($filters, $traceId);
            $body = $response['body'] ?? [];
            $items = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $filtered = array_filter($items, static function ($order): bool {
                $scheduleOne = $order['schedule_1'] ?? ($order['schedule_one'] ?? null);
                $scheduleTwo = $order['schedule_2'] ?? ($order['schedule_two'] ?? null);

                return $scheduleOne === null && $scheduleTwo === null;
            });

            $data = array_map(static function ($order): array {
                $item = $order['items'][0] ?? [];

                return [
                    'uuid' => (string) ($order['uuid'] ?? ''),
                    'customer_name' => (string) ($order['customer']['name'] ?? ''),
                    'customer_whatsapp' => (string) ($order['customer']['whatsapp'] ?? ''),
                    'product' => (string) ($item['product']['name'] ?? ''),
                    'created_at' => $order['created_at'] ?? null,
                ];
            }, array_values($filtered));

            $meta = $body['meta'] ?? [];
            $meta['count'] = count($data);
            $meta['total'] = $meta['total'] ?? count($data);
            $meta['source'] = $meta['source'] ?? 'crm';
            $meta['cache_hit'] = false;

            return new ReportResult($data, ['total' => count($data)], $meta, ['uuid', 'customer_name', 'customer_whatsapp', 'product', 'created_at']);
        },
    ],
    'phones.for_campaign' => [
        'type' => 'closure',
        'title' => 'WhatsApps de clientes eleg?veis para campanhas',
        'cache' => 600,
        'rules' => [
            'product[slug]' => v::optional(v::stringType()->length(1, 64)),
            'order[status]' => v::optional(v::stringType()->length(1, 64)),
            'format' => v::optional(v::in(['plain', 'json'])),
        ],
        'defaults' => [
            'order[status]' => 'payment_confirmed',
            'include' => 'customer',
        ],
        'columns' => ['whatsapp'],
        'sortable' => ['whatsapp'],
        'runner' => static function (EvydenciaApiClient $api, array $filters, string $traceId): ReportResult {
            $format = strtolower((string) ($filters['format'] ?? 'json')) === 'plain' ? 'plain' : 'json';
            unset($filters['format']);

            $response = $api->searchOrders($filters, $traceId);
            $body = $response['body'] ?? [];
            $items = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

            $phones = [];
            foreach ($items as $order) {
                $phone = $order['customer']['whatsapp'] ?? null;
                if (!is_string($phone) || trim($phone) === '') {
                    continue;
                }

                $normalized = preg_replace('/\D+/', '', $phone);
                if ($normalized === null || $normalized === '') {
                    continue;
                }

                $phones[$normalized] = ['whatsapp' => $normalized];
            }

            ksort($phones);
            $data = array_values($phones);

            if ($format === 'plain') {
                $lines = array_map(static fn ($row): string => $row['whatsapp'], $data);
                $data = array_map(static fn ($value): array => ['whatsapp' => $value], $lines);
            }

            $meta = $body['meta'] ?? [];
            $meta['count'] = count($data);
            $meta['total'] = $meta['total'] ?? count($data);
            $meta['source'] = $meta['source'] ?? 'crm';
            $meta['cache_hit'] = false;

            return new ReportResult($data, ['unique_numbers' => count($data)], $meta, ['whatsapp']);
        },
    ],
    'orders.without_participants' => [
        'type' => 'class',
        'class' => OrdersWithoutParticipantsReport::class,
    ],
    'orders.finalized_late_selections' => [
        'type' => 'class',
        'class' => FinalizedLateSelectionsReport::class,
    ],
    'orders.not_closed' => [
        'type' => 'class',
        'class' => NotClosedOrdersReport::class,
    ],
    'participants.under_8' => [
        'type' => 'class',
        'class' => ParticipantsUnder8Report::class,
    ],
    'orders.presale_vs_current' => [
        'type' => 'class',
        'class' => PresaleVsCurrentReport::class,
    ],
];


