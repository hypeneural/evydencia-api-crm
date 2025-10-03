<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ZapiClient
{
    private Client $httpClient;

    public function __construct(
        string $baseUrl,
        string $instance,
        string $token,
        private readonly string $clientToken,
        float $timeout,
        private readonly LoggerInterface $logger
    ) {
        $normalizedBase = rtrim($baseUrl, '/');
        $normalizedInstance = trim($instance);
        $normalizedToken = trim($token);

        $baseUri = sprintf(
            '%s/instances/%s/token/%s/',
            $normalizedBase,
            $normalizedInstance,
            $normalizedToken
        );

        $this->httpClient = new Client([
            'base_uri' => $baseUri,
            'timeout' => $timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendText(string $phone, string $message, string $traceId): array
    {
        return $this->post('send-text', [
            'phone' => $phone,
            'message' => $message,
        ], $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendAudio(array $payload, string $traceId): array
    {
        return $this->post('send-audio', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendImage(array $payload, string $traceId): array
    {
        return $this->post('send-image', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendDocument(string $extension, array $payload, string $traceId): array
    {
        $normalizedExtension = strtolower(trim($extension));
        $normalizedExtension = ltrim($normalizedExtension, '.');

        if ($normalizedExtension === '') {
            throw new RuntimeException('Document extension must not be empty.');
        }

        $endpoint = sprintf('send-document/%s', $normalizedExtension);

        return $this->post($endpoint, $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendImageStatus(string $image, ?string $caption, string $traceId): array
    {
        $payload = ['image' => $image];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->post('send-image-status', $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendVideoStatus(string $video, ?string $caption, string $traceId): array
    {
        $payload = ['video' => $video];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->post('send-video-status', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    private function post(string $endpoint, array $payload, string $traceId): array
    {
        try {
            $response = $this->httpClient->post(ltrim($endpoint, '/'), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Client-Token' => $this->clientToken,
                    'Trace-Id' => $traceId,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $exception) {
            $this->logger->error('Z-API request failed', [
                'trace_id' => $traceId,
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to communicate with Z-API.', 0, $exception);
        }

        $status = $response->getStatusCode();
        $bodyString = (string) $response->getBody();
        $decoded = $bodyString === '' ? [] : json_decode($bodyString, true);
        $isJson = !(($bodyString !== '') && $decoded === null);
        $body = $isJson && is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            $this->logger->warning('Z-API responded with error status', [
                'trace_id' => $traceId,
                'endpoint' => $endpoint,
                'status' => $status,
                'has_body' => $body !== [],
            ]);
        }

        $result = [
            'status' => $status,
            'body' => $body,
        ];

        if (!$isJson && $bodyString !== '') {
            $result['raw'] = $bodyString;
        }

        return $result;
    }
}
