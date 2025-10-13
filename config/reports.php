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
    'orders.photos_ready' => [
        'type' => 'closure',
        'title' => 'Clientes com fotos prontas para retirada',
        'description' => 'Lista pedidos com sessoes ja realizadas e com status que indicam fotos disponiveis para retirada.',
        'cache' => 300,
        'rules' => [
            'product[slug]' => v::optional(v::stringType()->length(1, 64)),
            'order[session-start]' => v::optional(v::date('Y-m-d')),
            'order[session-end]' => v::optional(v::date('Y-m-d')),
        ],
        'defaults' => [
            'product[slug]' => 'natal',
            'order[session-start]' => (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'order[session-end]' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'include' => 'items,customer,status',
        ],
        'columns' => [
            ['key' => 'id', 'label' => 'Pedido', 'type' => 'integer'],
            ['key' => 'uuid', 'label' => 'UUID', 'type' => 'string'],
            ['key' => 'schedule_datetime', 'label' => 'Sessao (bruta)', 'type' => 'string'],
            ['key' => 'schedule_1', 'label' => 'Sessao', 'type' => 'string'],
            ['key' => 'schedule_time', 'label' => 'Horario', 'type' => 'string'],
            ['key' => 'created_at', 'label' => 'Criado Em', 'type' => 'string'],
            ['key' => 'customer_first_name', 'label' => 'Primeiro Nome', 'type' => 'string'],
            ['key' => 'customer_name', 'label' => 'Nome Completo', 'type' => 'string'],
            ['key' => 'customer_whatsapp_plain', 'label' => 'WhatsApp (limpo)', 'type' => 'string'],
            ['key' => 'customer_whatsapp_formatted', 'label' => 'WhatsApp (BR)', 'type' => 'string'],
            ['key' => 'products', 'label' => 'Produtos', 'type' => 'string'],
            ['key' => 'status_name', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'link', 'label' => 'Link', 'type' => 'string'],
        ],
        'sortable' => ['schedule_1', 'customer_name', 'customer_first_name', 'status_name'],
        'runner' => static function (EvydenciaApiClient $api, array $input, string $traceId): ReportResult {
            $page = max(1, (int) ($input['page'] ?? 1));
            $perPage = max(1, min(500, (int) ($input['per_page'] ?? 100)));
            $allowedSort = ['schedule_1', 'customer_name', 'customer_first_name', 'status_name'];
            $sortField = isset($input['sort']) && is_string($input['sort']) && in_array($input['sort'], $allowedSort, true)
                ? $input['sort']
                : 'schedule_1';
            $direction = strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $fetchAll = ($input['fetch'] ?? 'page') === 'all';

            $filters = [];
            foreach ($input as $key => $value) {
                if (in_array($key, ['sort', 'dir', 'fetch', 'trace_id', 'page', 'per_page'], true)) {
                    continue;
                }
                $filters[$key] = $value;
            }

            unset($filters['item[slug]']);

            $today = new DateTimeImmutable('today');

            $filters['product[slug]'] = 'natal';
            $filters['order[session-start]'] = $today->sub(new DateInterval('P30D'))->format('Y-m-d');
            $filters['order[session-end]'] = $today->sub(new DateInterval('P1D'))->format('Y-m-d');
            unset($filters['order[status]']);

            $includes = [];
            if (isset($filters['include'])) {
                $parts = array_map('trim', explode(',', (string) $filters['include']));
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $includes[$part] = true;
                    }
                }
            }

            foreach (['items', 'customer', 'status'] as $requiredInclude) {
                $includes[$requiredInclude] = true;
            }
            $filters['include'] = implode(',', array_keys($includes));

            $baseApiFilters = $filters;

            $statusTargets = [
                ['slug' => 'session_scheduled', 'id' => 6],
                ['slug' => 'selection_finalized', 'id' => 9],
            ];

            $allowedStatusIds = [6, 9];
            $allowedStatusSlugs = array_values(array_unique(array_filter(array_map(
                static fn (array $target): ?string => isset($target['slug']) && is_string($target['slug'])
                    ? strtolower(trim((string) $target['slug']))
                    : null,
                $statusTargets
            ))));

            $extractQuery = static function (string $link): array {
                $components = parse_url($link);
                if ($components === false || !isset($components['query'])) {
                    return [];
                }
                parse_str($components['query'], $query);

                return is_array($query) ? $query : [];
            };

            $orders = [];
            $meta = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'count' => 0,
                'cache_hit' => false,
                'source' => 'crm',
            ];
            $appliedStatusFilters = [];
            $crmRequests = 0;

            $maxIterations = 30;

            $collectOrders = static function (array $batch) use (&$orders, $allowedStatusIds, $allowedStatusSlugs): int {
                $inserted = 0;

                foreach ($batch as $order) {
                    if (!is_array($order)) {
                        continue;
                    }

                    $status = $order['status'] ?? null;
                    $statusId = 0;
                    $statusSlug = null;
                    if (is_array($status)) {
                        $statusId = (int) ($status['id'] ?? 0);
                        $slug = $status['slug'] ?? ($status['code'] ?? null);
                        if (is_string($slug) && $slug !== '') {
                            $statusSlug = strtolower($slug);
                        }
                    } else {
                        $statusId = (int) ($order['status_id'] ?? $status ?? 0);
                    }

                    $slugAllowed = $statusSlug !== null && in_array($statusSlug, $allowedStatusSlugs, true);
                    $idAllowed = in_array($statusId, $allowedStatusIds, true);

                    if (!$slugAllowed && !$idAllowed) {
                        continue;
                    }

                    $orderKey = null;
                    if (isset($order['uuid']) && is_string($order['uuid']) && $order['uuid'] !== '') {
                        $orderKey = 'uuid:' . strtolower($order['uuid']);
                    } elseif (isset($order['id'])) {
                        $orderKey = 'id:' . (string) $order['id'];
                    }

                    if ($orderKey === null) {
                        $orders[] = $order;
                        $inserted++;
                        continue;
                    }

                    $isNew = !isset($orders[$orderKey]);
                    $orders[$orderKey] = $order;
                    if ($isNew) {
                        $inserted++;
                    }
                }

                return $inserted;
            };

            $fetchStatus = static function (string $statusValue) use (
                $api,
                $traceId,
                $extractQuery,
                $baseApiFilters,
                $maxIterations,
                &$appliedStatusFilters,
                &$crmRequests,
                $collectOrders
            ): bool {
                $statusValue = trim($statusValue);
                if ($statusValue === '') {
                    return false;
                }

                $apiFilters = $baseApiFilters;
                $apiFilters['order[status]'] = $statusValue;
                $apiFilters['page'] = 1;
                $apiFilters['per_page'] = 200;

                $iteration = 0;
                $totalInserted = 0;

                do {
                    $crmRequests++;
                    $response = $api->searchOrders($apiFilters, $traceId);
                    $body = $response['body'] ?? [];
                    $batch = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

                    if ($batch === []) {
                        break;
                    }

                    $totalInserted += $collectOrders($batch);

                    $links = $body['links'] ?? [];
                    $next = is_array($links) ? ($links['next'] ?? null) : null;

                    if (!is_string($next) || $next === '') {
                        break;
                    }

                    $nextQuery = $extractQuery($next);
                    if ($nextQuery === []) {
                        break;
                    }

                    $apiFilters = array_merge($apiFilters, $nextQuery);
                } while (++$iteration < $maxIterations);

                if (!array_key_exists($statusValue, $appliedStatusFilters)) {
                    $appliedStatusFilters[$statusValue] = 0;
                }
                $appliedStatusFilters[$statusValue] += $totalInserted;

                return $totalInserted > 0;
            };

            foreach ($statusTargets as $target) {
                $slug = isset($target['slug']) && is_string($target['slug']) ? $target['slug'] : '';
                $hasResults = $fetchStatus($slug);

                if ($hasResults) {
                    continue;
                }

                $id = isset($target['id']) ? (int) $target['id'] : 0;
                if ($id > 0) {
                    $fetchStatus((string) $id);
                }
            }

            if ($orders === []) {
                $fallbackFilters = $baseApiFilters;
                $fallbackFilters['page'] = 1;
                $fallbackFilters['per_page'] = 200;

                $iteration = 0;
                $fallbackInserted = 0;

                do {
                    $crmRequests++;
                    $response = $api->searchOrders($fallbackFilters, $traceId);
                    $body = $response['body'] ?? [];
                    $batch = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

                    if ($batch === []) {
                        break;
                    }

                    $fallbackInserted += $collectOrders($batch);

                    $links = $body['links'] ?? [];
                    $next = is_array($links) ? ($links['next'] ?? null) : null;

                    if (!is_string($next) || $next === '') {
                        break;
                    }

                    $nextQuery = $extractQuery($next);
                    if ($nextQuery === []) {
                        break;
                    }

                    $fallbackFilters = array_merge($fallbackFilters, $nextQuery);
                } while (++$iteration < $maxIterations);

                if ($fallbackInserted > 0) {
                    $appliedStatusFilters['__fallback__'] = ($appliedStatusFilters['__fallback__'] ?? 0) + $fallbackInserted;
                }
            }

            $meta['crm_requests'] = $crmRequests;
            $meta['status_filters'] = array_map(static fn ($count): int => (int) $count, $appliedStatusFilters);
            $meta['filters_snapshot'] = [
                'product[slug]' => $filters['product[slug]'],
                'order[session-start]' => $filters['order[session-start]'],
                'order[session-end]' => $filters['order[session-end]'],
            ];

            $formatSchedule = static function (?string $value): array {
                if (!is_string($value) || trim($value) === '') {
                    return [
                        'date' => null,
                        'time' => null,
                        'iso' => null,
                        'timestamp' => null,
                    ];
                }

                try {
                    $date = new DateTimeImmutable($value);
                } catch (\Throwable) {
                    return [
                        'date' => $value,
                        'time' => null,
                        'iso' => $value,
                        'timestamp' => null,
                    ];
                }

                return [
                    'date' => $date->format('d/m/y'),
                    'time' => $date->format('H:i'),
                    'iso' => $date->format('Y-m-d H:i:s'),
                    'timestamp' => $date->getTimestamp(),
                ];
            };

            $normalizePhone = static function (?string $value): ?string {
                if (!is_string($value) || trim($value) === '') {
                    return null;
                }

                $digits = preg_replace('/\D+/', '', $value) ?? '';
                if ($digits === '') {
                    return null;
                }

                if (str_starts_with($digits, '55') && strlen($digits) > 11) {
                    $digits = substr($digits, 2);
                }

                while (strlen($digits) > 11) {
                    $digits = substr($digits, -11);
                }

                return $digits;
            };

            $formatPhone = static function (?string $digits): ?string {
                if ($digits === null || $digits === '') {
                    return null;
                }

                $length = strlen($digits);

                if ($length === 11) {
                    return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
                }

                if ($length === 10) {
                    return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
                }

                if ($length === 9) {
                    return sprintf('%s-%s', substr($digits, 0, 5), substr($digits, 5));
                }

                if ($length === 8) {
                    return sprintf('%s-%s', substr($digits, 0, 4), substr($digits, 4));
                }

                return $digits;
            };

            $extractFirstName = static function (?string $name): ?string {
                if (!is_string($name)) {
                    return null;
                }

                $trimmed = trim($name);
                if ($trimmed === '') {
                    return null;
                }

                $parts = preg_split('/\s+/', $trimmed);
                if (!is_array($parts) || $parts === []) {
                    return $trimmed;
                }

                return $parts[0] ?? $trimmed;
            };

            $collectProducts = static function (array $order): string {
                $items = $order['items'] ?? [];
                if (!is_array($items)) {
                    return '';
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $product = $item['product'] ?? [];
                    $name = is_array($product) ? ($product['name'] ?? null) : null;
                    if (is_string($name) && $name !== '') {
                        return $name;
                    }
                }

                return '';
            };

            $ordersList = array_values($orders);

            $rows = array_map(static function (array $order) use ($formatSchedule, $normalizePhone, $formatPhone, $extractFirstName, $collectProducts): array {
                $scheduleRaw = $order['schedule_1'] ?? ($order['schedule_one'] ?? null);
                $schedule = $formatSchedule(is_string($scheduleRaw) ? $scheduleRaw : null);

                $customer = $order['customer'] ?? [];
                $customerName = is_array($customer) ? ($customer['name'] ?? '') : '';
                $phoneRaw = is_array($customer) ? ($customer['whatsapp'] ?? null) : null;
                $phonePlain = $normalizePhone(is_string($phoneRaw) ? $phoneRaw : null);

                $status = $order['status'] ?? [];
                $statusName = is_array($status) ? (string) ($status['name'] ?? '') : (string) ($order['status_name'] ?? '');

                $id = $order['id'] ?? null;
                $uuid = isset($order['uuid']) && is_string($order['uuid']) ? $order['uuid'] : null;

                return [
                    'id' => is_numeric($id) ? (int) $id : (string) $id,
                    'uuid' => $uuid,
                    'schedule_datetime' => $schedule['iso'],
                    'schedule_1' => $schedule['date'],
                    'schedule_time' => $schedule['time'],
                    'created_at' => isset($order['created_at']) ? (string) $order['created_at'] : null,
                    'schedule_sort' => $schedule['timestamp'],
                    'customer_first_name' => $extractFirstName($customerName),
                    'customer_name' => $customerName,
                    'customer_whatsapp_plain' => $phonePlain,
                    'customer_whatsapp_formatted' => $formatPhone($phonePlain),
                    'products' => $collectProducts($order),
                    'status_name' => $statusName,
                    'link' => $uuid === null ? null : sprintf('https://evydencia.com/gestao/pedidos/%s/detalhes', $uuid),
                ];
            }, $ordersList);

            $sortKey = match ($sortField) {
                'customer_name' => 'customer_name',
                'customer_first_name' => 'customer_first_name',
                'status_name' => 'status_name',
                default => 'schedule_sort',
            };

            usort($rows, static function (array $left, array $right) use ($sortKey, $direction): int {
                $l = $left[$sortKey] ?? null;
                $r = $right[$sortKey] ?? null;

                if ($l === $r) {
                    return 0;
                }

                if ($l === null) {
                    return $direction === 'asc' ? 1 : -1;
                }

                if ($r === null) {
                    return $direction === 'asc' ? -1 : 1;
                }

                if (is_numeric($l) && is_numeric($r)) {
                    return $direction === 'asc' ? ($l <=> $r) : ($r <=> $l);
                }

                return $direction === 'asc'
                    ? strnatcasecmp((string) $l, (string) $r)
                    : strnatcasecmp((string) $r, (string) $l);
            });

            $totalRows = count($rows);
            if (!$fetchAll) {
                $offset = max(0, ($page - 1) * $perPage);
                $rows = array_slice($rows, $offset, $perPage);
                $meta['page'] = $page;
                $meta['per_page'] = $perPage;
            } else {
                $meta['page'] = 1;
                $meta['per_page'] = $totalRows;
            }

            $rows = array_map(static function (array $row): array {
                unset($row['schedule_sort']);
                return $row;
            }, $rows);

            $meta['total'] = $totalRows;
            $meta['count'] = count($rows);

            $summary = [
                'total' => $totalRows,
            ];

            return new ReportResult($rows, $summary, $meta, []);
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


