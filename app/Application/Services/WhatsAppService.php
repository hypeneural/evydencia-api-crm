<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Exception\ZapiRequestException;
use App\Infrastructure\Http\ZapiClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class WhatsAppService
{
    public function __construct(
        private readonly ZapiClient $client,
        private readonly LoggerInterface $logger,
        private readonly bool $debug
    ) {
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendText(string $traceId, string $phone, string $message): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp text message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $response = $this->client->sendText($normalizedPhone, $message, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendAudio(string $traceId, string $phone, string $audio, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp audio message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'audio' => $audio,
            ],
            $this->filterOptions($options, ['delayMessage', 'delayTyping', 'viewOnce', 'async', 'waveform'])
        );

        $response = $this->client->sendAudio($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendImage(string $traceId, string $phone, string $image, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp image message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'image' => $image,
            ],
            $this->filterOptions($options, ['caption', 'messageId', 'delayMessage', 'viewOnce'])
        );

        $response = $this->client->sendImage($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendDocument(string $traceId, string $phone, string $extension, string $document, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
        if ($normalizedExtension === '') {
            throw new RuntimeException('Document extension must not be empty.');
        }

        $this->logger->info('Sending WhatsApp document message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
            'extension' => $normalizedExtension,
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'document' => $document,
            ],
            $this->filterOptions($options, ['fileName', 'caption', 'messageId', 'delayMessage', 'editDocumentMessageId'])
        );

        $response = $this->client->sendDocument($normalizedExtension, $payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendImageStatus(string $traceId, string $image, ?string $caption): array
    {
        $this->logger->info('Sending WhatsApp image status', [
            'trace_id' => $traceId,
        ]);

        $response = $this->client->sendImageStatus($image, $caption, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendVideoStatus(string $traceId, string $video, ?string $caption): array
    {
        $this->logger->info('Sending WhatsApp video status', [
            'trace_id' => $traceId,
        ]);

        $response = $this->client->sendVideoStatus($video, $caption, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string} $response
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function handleResponse(array $response, string $traceId): array
    {
        $status = $response['status'];
        $body = $response['body'];

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Z-API returned non-success status', [
                'trace_id' => $traceId,
                'status' => $status,
            ]);

            throw new ZapiRequestException($status, $body);
        }

        $data = [
            'zaapId' => $this->extractValue($body, ['zaapId', 'zaap_id']),
            'messageId' => $this->extractValue($body, ['messageId', 'message_id', 'id']),
        ];

        return [
            'data' => array_filter($data, static fn ($value) => $value !== null && $value !== ''),
            'meta' => [
                'provider_status' => $status,
                'provider_body' => $this->debug ? $body : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string> $allowed
     * @return array<string, mixed>
     */
    private function filterOptions(array $options, array $allowed): array
    {
        $filtered = array_intersect_key($options, array_flip($allowed));

        return array_filter(
            $filtered,
            static fn ($value) => $value !== null
        );
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits;
    }

    private function maskPhone(string $phone): string
    {
        if ($phone === '') {
            return $phone;
        }

        $length = strlen($phone);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visible = substr($phone, -4);

        return str_repeat('*', $length - 4) . $visible;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $body
     */
    private function extractValue(array $body, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($body) && array_key_exists($key, $body)) {
                return $body[$key];
            }
        }

        return null;
    }
}
