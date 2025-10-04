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
    public function sendPtv(array $payload, string $traceId): array
    {
        return $this->post('send-ptv', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendLocation(array $payload, string $traceId): array
    {
        return $this->post('send-location', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendLink(array $payload, string $traceId): array
    {
        return $this->post('send-link', $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function getProfilePicture(string $phone, string $traceId): array
    {
        return $this->get('profile-picture', ['phone' => $phone], $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendSticker(array $payload, string $traceId): array
    {
        return $this->post('send-sticker', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendGif(array $payload, string $traceId): array
    {
        return $this->post('send-gif', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendCarousel(array $payload, string $traceId): array
    {
        return $this->post('send-carousel', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendTextStatus(array $payload, string $traceId): array
    {
        return $this->post('send-text-status', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendOptionList(array $payload, string $traceId): array
    {
        return $this->post('send-option-list', $payload, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function pinMessage(array $payload, string $traceId): array
    {
        return $this->post('pin-message', $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function sendCall(array $payload, string $traceId): array
    {
        return $this->post('send-call', $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function getContacts(array $query, string $traceId): array
    {
        return $this->get('contacts', $query, $traceId);
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function addContacts(array $payload, string $traceId): array
    {
        return $this->post('contacts/add', $payload, $traceId);
    }

    /**
     * @param array<int, string> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function removeContacts(array $payload, string $traceId): array
    {
        return $this->delete('contacts/remove', $payload, $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function getContactMetadata(string $phone, string $traceId): array
    {
        $endpoint = sprintf('contacts/%s', $phone);

        return $this->get($endpoint, [], $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function addChatTag(string $phone, string $tag, string $traceId): array
    {
        $endpoint = sprintf('chats/%s/tags/%s/add', $phone, rawurlencode($tag));

        return $this->put($endpoint, [], $traceId);
    }

    /**
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    public function removeChatTag(string $phone, string $tag, string $traceId): array
    {
        $endpoint = sprintf('chats/%s/tags/%s/remove', $phone, rawurlencode($tag));

        return $this->put($endpoint, [], $traceId);
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

    /**
     * @param array<string, mixed> $query
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    private function get(string $endpoint, array $query, string $traceId): array
    {
        try {
            $response = $this->httpClient->get(ltrim($endpoint, '/'), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Client-Token' => $this->clientToken,
                    'Trace-Id' => $traceId,
                ],
                'query' => $query,
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

    /**
     * @param array<string, mixed>|array<int, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    private function put(string $endpoint, array $payload, string $traceId): array
    {
        try {
            $response = $this->httpClient->put(ltrim($endpoint, '/'), [
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

    /**
     * @param array<string, mixed>|array<int, mixed> $payload
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    private function delete(string $endpoint, array $payload, string $traceId): array
    {
        try {
            $response = $this->httpClient->delete(ltrim($endpoint, '/'), [
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
