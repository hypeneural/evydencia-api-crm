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
    public function sendPtv(string $traceId, string $phone, string $ptv, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp PTV message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'ptv' => $ptv,
            ],
            $this->filterOptions($options, ['messageId', 'delayMessage'])
        );

        $response = $this->client->sendPtv($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendSticker(string $traceId, string $phone, string $sticker, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp sticker message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'sticker' => $sticker,
            ],
            $this->filterOptions($options, ['messageId', 'delayMessage', 'stickerAuthor'])
        );

        $response = $this->client->sendSticker($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendGif(string $traceId, string $phone, string $gif, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp GIF message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'gif' => $gif,
            ],
            $this->filterOptions($options, ['messageId', 'delayMessage', 'caption'])
        );

        $response = $this->client->sendGif($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendLocation(
        string $traceId,
        string $phone,
        string $title,
        string $address,
        string $latitude,
        string $longitude,
        array $options = []
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp location message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'title' => $title,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            $this->filterOptions($options, ['messageId', 'delayMessage'])
        );

        $response = $this->client->sendLocation($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendLink(
        string $traceId,
        string $phone,
        string $message,
        string $image,
        string $linkUrl,
        string $title,
        string $linkDescription,
        array $options = []
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp link message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'message' => $message,
                'image' => $image,
                'linkUrl' => $linkUrl,
                'title' => $title,
                'linkDescription' => $linkDescription,
            ],
            $this->filterOptions($options, ['delayMessage', 'delayTyping', 'linkType'])
        );

        $response = $this->client->sendLink($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendCarousel(
        string $traceId,
        string $phone,
        string $message,
        array $carousel,
        array $options = []
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp carousel message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'message' => $message,
                'carousel' => $carousel,
            ],
            $this->filterOptions($options, ['delayMessage'])
        );

        $response = $this->client->sendCarousel($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendTextStatus(string $traceId, string $message): array
    {
        $this->logger->info('Sending WhatsApp text status', [
            'trace_id' => $traceId,
        ]);

        $response = $this->client->sendTextStatus(['message' => $message], $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @param array<string, mixed> $optionList
     * @param array<string, mixed> $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendOptionList(
        string $traceId,
        string $phone,
        string $message,
        array $optionList,
        array $options = []
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp option list message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = array_merge(
            [
                'phone' => $normalizedPhone,
                'message' => $message,
                'optionList' => $optionList,
            ],
            $this->filterOptions($options, ['delayMessage'])
        );

        $response = $this->client->sendOptionList($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function pinMessage(
        string $traceId,
        string $phone,
        string $messageId,
        string $messageAction,
        ?string $duration
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Managing WhatsApp pinned message', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
            'action' => $messageAction,
        ]);

        $payload = array_filter(
            [
                'phone' => $normalizedPhone,
                'messageId' => $messageId,
                'messageAction' => $messageAction,
                'pinMessageDuration' => $duration,
            ],
            static fn ($value) => $value !== null && $value !== ''
        );

        $response = $this->client->pinMessage($payload, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function sendCall(string $traceId, string $phone, ?int $duration): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Sending WhatsApp call request', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $payload = ['phone' => $normalizedPhone];
        if ($duration !== null) {
            $payload['callDuration'] = $duration;
        }

        $response = $this->client->sendCall($payload, $traceId);

        return $this->handleResponse($response, $traceId);
    }

    /**
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    public function getContacts(string $traceId, int $page, int $pageSize): array
    {
        $this->logger->info('Fetching WhatsApp contacts', [
            'trace_id' => $traceId,
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        $response = $this->client->getContacts([
            'page' => $page,
            'pageSize' => $pageSize,
        ], $traceId);

        $result = $this->handleGeneric($response, $traceId);
        $rawData = $result['data'];
        $contacts = [];
        if (is_array($rawData)) {
            if (isset($rawData['contacts']) && is_array($rawData['contacts'])) {
                $contacts = array_values($rawData['contacts']);
            } elseif (array_is_list($rawData)) {
                $contacts = $rawData;
            }
        }

        return [
            'data' => $contacts,
            'meta' => $result['meta'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addContacts(string $traceId, array $contacts): array
    {
        $this->logger->info('Adding WhatsApp contacts', [
            'trace_id' => $traceId,
            'count' => count($contacts),
        ]);

        $response = $this->client->addContacts($contacts, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @param array<int, string> $phones
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeContacts(string $traceId, array $phones): array
    {
        $this->logger->info('Removing WhatsApp contacts', [
            'trace_id' => $traceId,
            'count' => count($phones),
        ]);

        $response = $this->client->removeContacts($phones, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function getContactMetadata(string $traceId, string $phone): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Fetching WhatsApp contact metadata', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $response = $this->client->getContactMetadata($normalizedPhone, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addChatTag(string $traceId, string $phone, string $tag): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedTag = trim($tag);
        $this->logger->info('Adding WhatsApp chat tag', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
            'tag' => $normalizedTag,
        ]);

        $response = $this->client->addChatTag($normalizedPhone, $normalizedTag, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeChatTag(string $traceId, string $phone, string $tag): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedTag = trim($tag);
        $this->logger->info('Removing WhatsApp chat tag', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
            'tag' => $normalizedTag,
        ]);

        $response = $this->client->removeChatTag($normalizedPhone, $normalizedTag, $traceId);

        return $this->handleGeneric($response, $traceId);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function getProfilePicture(string $traceId, string $phone): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->logger->info('Fetching WhatsApp profile picture', [
            'trace_id' => $traceId,
            'phone' => $this->maskPhone($normalizedPhone),
        ]);

        $response = $this->client->getProfilePicture($normalizedPhone, $traceId);

        $status = $response['status'];
        $body = $response['body'];

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Z-API returned non-success status for profile picture', [
                'trace_id' => $traceId,
                'status' => $status,
            ]);

            throw new ZapiRequestException($status, $body);
        }

        $link = $this->extractProfileLink($body);

        return [
            'data' => array_filter([
                'link' => $link,
            ], static fn ($value) => $value !== null && $value !== ''),
            'meta' => [
                'provider_status' => $status,
                'provider_body' => $this->debug ? $body : null,
            ],
        ];
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

    /**
     * @param array<string, mixed>|array<int, mixed> $body
     */
    private function extractProfileLink(array $body): ?string
    {
        if (isset($body['link']) && is_string($body['link']) && $body['link'] !== '') {
            return $body['link'];
        }

        foreach ($body as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['link']) && is_string($item['link']) && $item['link'] !== '') {
                return $item['link'];
            }
        }

        return null;
    }

    /**
     * @param array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string} $response
     * @return array{data: array<string, mixed>|array<int, mixed>, meta: array<string, mixed>}
     */
    private function handleGeneric(array $response, string $traceId): array
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

        $data = is_array($body) ? $body : [];

        return [
            'data' => $data,
            'meta' => [
                'provider_status' => $status,
                'provider_body' => $this->debug ? $body : null,
            ],
        ];
    }
}
