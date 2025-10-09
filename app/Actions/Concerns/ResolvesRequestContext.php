<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use Psr\Http\Message\ServerRequestInterface as Request;

trait ResolvesRequestContext
{
    protected function resolveUserId(Request $request): ?string
    {
        $userId = $request->getAttribute('user_id');
        if (is_string($userId) && $userId !== '') {
            return $userId;
        }

        $user = $request->getAttribute('user');
        if (is_array($user)) {
            $candidate = $user['id'] ?? $user['uuid'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $header = $request->getHeaderLine('X-User-Id');
        $header = trim($header);
        if ($header !== '') {
            return $header;
        }

        return null;
    }

    protected function resolveOriginIp(Request $request): ?string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $parts = array_filter(array_map('trim', explode(',', $forwarded)));
            if ($parts !== []) {
                return $parts[0];
            }
        }

        $server = $request->getServerParams();
        $remoteAddr = $server['REMOTE_ADDR'] ?? $server['SERVER_ADDR'] ?? null;

        return is_string($remoteAddr) && $remoteAddr !== '' ? $remoteAddr : null;
    }

    protected function resolveUserAgent(Request $request): ?string
    {
        $userAgent = $request->getHeaderLine('User-Agent');

        return $userAgent !== '' ? $userAgent : null;
    }
}

