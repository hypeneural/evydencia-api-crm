<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Settings\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class EvydenciaApiClient
{
    private string $token;
    private float $timeout;

    public function __construct(
        private readonly Client $httpClient,
        Settings $settings,
        private readonly LoggerInterface $logger
    ) {
        $crm = $settings->getCrm();
        $this->token = $crm['token'] ?? '';
        $this->timeout = isset($crm['timeout']) ? (float) $crm['timeout'] : 30.0;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function get(string $endpoint, array $query, string $traceId): array
    {
        return $this->request('GET', $endpoint, ['query' => $query], $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function post(string $endpoint, array $payload, string $traceId): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload], $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function put(string $endpoint, array $payload, string $traceId): array
    {
        return $this->request('PUT', $endpoint, ['json' => $payload], $traceId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function searchOrders(array $query, string $traceId): array
    {
        return $this->get('orders/search', $query, $traceId);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function fetchOrderDetail(string $uuid, string $traceId): array
    {
        return $this->get(sprintf('orders/%s/detail', $uuid), [], $traceId);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function updateOrderStatus(string $uuid, string $status, ?string $note, string $traceId): array
    {
        $payload = [
            'uuid' => $uuid,
            'status' => $status,
        ];

        if ($note !== null && $note !== '') {
            $payload['note'] = $note;
        }

        return $this->put('order/status', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function fetchSoldItems(array $query, string $traceId): array
    {
        return $this->get('reports/sold-items', $query, $traceId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    public function fetchCampaignSchedule(array $query, string $traceId): array
    {
        return $this->get('campaigns/schedule/search', $query, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, body: array<string, mixed>|array<int, mixed>, raw?: string}
     */
    private function request(string $method, string $uri, array $options, string $traceId): array
    {
        if ($this->token === '') {
            throw new RuntimeException('CRM token is not configured.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => $this->token,
            'Trace-Id' => $traceId,
        ];

        $normalizedUri = ltrim($uri, '/');
        $options['headers'] = isset($options['headers'])
            ? array_merge($headers, $options['headers'])
            : $headers;
        $options['timeout'] = $options['timeout'] ?? $this->timeout;

        try {
            $response = $this->httpClient->request($method, $normalizedUri, $options);
            $statusCode = $response->getStatusCode();
            $bodyString = (string) $response->getBody();
            $decoded = $bodyString === '' ? [] : json_decode($bodyString, true);
            $isJson = !($bodyString !== '' && $decoded === null);
            $payload = $isJson && is_array($decoded) ? $decoded : [];

            if ($statusCode >= 400) {
                $this->logger->warning('CRM responded with an error status', [
                    'trace_id' => $traceId,
                    'method' => $method,
                    'uri' => $normalizedUri,
                    'status_code' => $statusCode,
                    'payload_keys' => array_keys((array) $payload),
                ]);

                $errorPayload = $payload;
                if (!$isJson && $bodyString !== '') {
                    $errorPayload = ['raw' => $bodyString];
                }

                throw new CrmRequestException($statusCode, $errorPayload, sprintf('CRM responded with status %d.', $statusCode));
            }

            $result = [
                'status' => $statusCode,
                'body' => $payload,
            ];

            if (!$isJson && $bodyString !== '') {
                $result['raw'] = $bodyString;
            }

            return $result;
        } catch (ConnectException $exception) {
            $this->logger->error('Unable to reach CRM', [
                'trace_id' => $traceId,
                'method' => $method,
                'uri' => $normalizedUri,
                'error' => $exception->getMessage(),
            ]);

            throw new CrmUnavailableException('CRM connection failed.', 0, $exception);
        } catch (RequestException $exception) {
            if ($exception->getResponse() === null) {
                $this->logger->error('CRM request failed without response', [
                    'trace_id' => $traceId,
                    'method' => $method,
                    'uri' => $normalizedUri,
                    'error' => $exception->getMessage(),
                ]);

                throw new CrmUnavailableException('CRM request failed.', 0, $exception);
            }

            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            $bodyString = (string) $response->getBody();
            $decoded = $bodyString === '' ? [] : json_decode($bodyString, true);
            $isJson = !($bodyString !== '' && $decoded === null);
            $payload = $isJson && is_array($decoded) ? $decoded : [];

            $this->logger->warning('CRM responded with an error (RequestException)', [
                'trace_id' => $traceId,
                'method' => $method,
                'uri' => $normalizedUri,
                'status_code' => $statusCode,
                'payload_keys' => array_keys((array) $payload),
            ]);

            $errorPayload = $payload;
            if (!$isJson && $bodyString !== '') {
                $errorPayload = ['raw' => $bodyString];
            }

            throw new CrmRequestException($statusCode, $errorPayload, sprintf('CRM responded with status %d.', $statusCode));
        } catch (GuzzleException $exception) {
            $this->logger->error('Unexpected Guzzle error while communicating with CRM', [
                'trace_id' => $traceId,
                'method' => $method,
                'uri' => $normalizedUri,
                'error' => $exception->getMessage(),
            ]);

            throw new CrmUnavailableException('CRM request error.', 0, $exception);
        }
    }
}

