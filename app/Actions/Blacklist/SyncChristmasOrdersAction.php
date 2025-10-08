<?php
declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Http\EvydenciaApiClient;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class SyncChristmasOrdersAction
{
    public function __construct(
        private readonly EvydenciaApiClient $crm,
        private readonly BlacklistService $blacklist,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/blacklist/christmas-orders/sync",
     *     tags={"Blacklist"},
     *     summary="Sincroniza clientes com pedidos de Natal",
     *     description="Consulta o CRM Evydencia para clientes com pedido de Natal fechado e garante que estejam na blacklist local.",
     *     @OA\Response(
     *         response=200,
     *         description="Sincronização concluída",
     *         @OA\JsonContent(ref="#/components/schemas/BlacklistSyncChristmasOrdersResponse")
     *     ),
     *     @OA\Response(
     *         response=502,
     *         description="Falha ao consultar o CRM",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erro interno",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     )
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $crmResponse = $this->crm->fetchCustomersWithChristmasOrders($traceId);
            $customers = $this->extractCustomers($crmResponse['body'] ?? []);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM indisponivel')
                ->withHeader('X-Request-Id', $traceId);
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('Erro ao consultar CRM (status %d).', $exception->getStatusCode())
            )->withHeader('X-Request-Id', $traceId);
        } catch (Throwable $exception) {
            $this->logger->error('blacklist.sync_christmas_orders.fetch_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel consultar o CRM.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $metrics = [
            'total_crm_customers' => count($customers),
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'invalid' => 0,
        ];

        $failures = [];

        foreach ($customers as $index => $customer) {
            $normalized = $this->normalizeCustomer($customer);
            if ($normalized === null) {
                $metrics['invalid']++;
                $failures[] = [
                    'index' => $index,
                    'reason' => 'Dados incompletos ou whatsapp invalido.',
                ];
                continue;
            }

            try {
                $result = $this->blacklist->ensureClosedOrderEntry([
                    'name' => $normalized['name'],
                    'whatsapp' => $normalized['whatsapp'],
                    'observation' => $normalized['observation'],
                ], $traceId);
            } catch (ValidationException $exception) {
                $metrics['invalid']++;
                $failures[] = [
                    'index' => $index,
                    'reason' => 'Dados invalidos para inserir na blacklist.',
                    'errors' => $exception->getErrors(),
                ];
                continue;
            } catch (Throwable $exception) {
                $metrics['skipped']++;
                $failures[] = [
                    'index' => $index,
                    'reason' => 'Erro ao persistir contato na blacklist.',
                ];

                $this->logger->error('blacklist.sync_christmas_orders.persist_failed', [
                    'trace_id' => $traceId,
                    'index' => $index,
                    'customer' => $this->safeLogCustomer($customer),
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            if ($result['created']) {
                $metrics['inserted']++;
            } elseif ($result['updated']) {
                $metrics['updated']++;
            } else {
                $metrics['skipped']++;
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $data = array_merge($metrics, [
            'failures' => $failures,
        ]);

        $meta = [
            'page' => 1,
            'per_page' => $metrics['total_crm_customers'],
            'total' => $metrics['total_crm_customers'],
            'elapsed_ms' => $elapsedMs,
        ];

        $links = [
            'self' => (string) $request->getUri(),
        ];

        return $this->responder
            ->successResource($response, $data, $traceId, $meta, $links)
            ->withHeader('X-Request-Id', $traceId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCustomers(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            return [];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->normalizeList($payload['data']);
        }

        return $this->normalizeList($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCustomer(mixed $customer): ?array
    {
        if (!is_array($customer)) {
            return null;
        }

        $name = isset($customer['name']) ? trim((string) $customer['name']) : '';
        if ($name === '' && isset($customer['email']) && is_string($customer['email'])) {
            $name = trim($customer['email']);
        }

        $whatsappRaw = isset($customer['whatsapp']) ? (string) $customer['whatsapp'] : '';
        $digits = preg_replace('/\D+/', '', $whatsappRaw);
        $whatsapp = $digits ?? '';

        if (strlen($whatsapp) < 10 || strlen($whatsapp) > 13) {
            return null;
        }

        if ($name === '') {
            $name = 'Cliente sem nome';
        }

        $observation = null;
        if (isset($customer['email']) && is_string($customer['email']) && trim($customer['email']) !== '') {
            $observation = sprintf('Email CRM: %s', trim($customer['email']));
        }

        return [
            'name' => $name,
            'whatsapp' => $whatsapp,
            'observation' => $observation,
        ];
    }

    /**
     * @param array<int, mixed>|array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(array $data): array
    {
        if ($data === []) {
            return [];
        }

        return array_is_list($data) ? $data : [$data];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeLogCustomer(mixed $customer): array
    {
        if (!is_array($customer)) {
            return [];
        }

        $allowedKeys = ['name', 'whatsapp', 'email'];
        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $customer)) {
                $filtered[$key] = $customer[$key];
            }
        }

        return $filtered;
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');
        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}

