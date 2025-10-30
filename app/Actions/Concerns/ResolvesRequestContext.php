<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use Exception;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared helpers to extract request-scoped context (trace, user, origin).
 */
trait ResolvesRequestContext
{
    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $headerTrace = trim($request->getHeaderLine('X-Trace-Id'));
            if ($headerTrace !== '') {
                $traceId = $headerTrace;
            } else {
                $headerRequestId = trim($request->getHeaderLine('X-Request-Id'));
                if ($headerRequestId !== '') {
                    $traceId = $headerRequestId;
                } else {
                    $traceId = $this->generateTraceId();
                }
            }
        }

        return $traceId;
    }

    protected function resolveUserId(Request $request): ?string
    {
        $userId = $request->getAttribute('user_id');
        if (is_string($userId) && $userId !== '') {
            return $userId;
        }

        $user = $request->getAttribute('user');
        if (is_array($user)) {
            $userId = $user['id'] ?? $user['uuid'] ?? null;
            if (is_string($userId) && $userId !== '') {
                return $userId;
            }
        }

        $headerUserId = trim($request->getHeaderLine('X-User-Id'));
        if ($headerUserId !== '') {
            return $headerUserId;
        }

        return null;
    }

    protected function resolveOriginIp(Request $request): ?string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $ips = array_filter(array_map('trim', explode(',', $forwarded)));
            if ($ips !== []) {
                return $ips[0];
            }
        }

        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? $serverParams['SERVER_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    protected function resolveUserAgent(Request $request): ?string
    {
        $userAgent = $request->getHeaderLine('User-Agent');

        return $userAgent !== '' ? $userAgent : null;
    }

    private function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception) {
            return bin2hex(pack('H*', substr(sha1((string) microtime(true)), 0, 16)));
        }
    }
}
