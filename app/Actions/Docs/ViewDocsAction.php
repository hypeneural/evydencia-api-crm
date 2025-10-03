<?php
declare(strict_types=1);

namespace App\Actions\Docs;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

final class ViewDocsAction
{
    private string $indexPath;

    public function __construct()
    {
        $this->indexPath = dirname(__DIR__, 2) . '/public/docs/index.html';
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (!is_file($this->indexPath)) {
            throw new RuntimeException('Swagger UI bundle is missing. Run composer openapi:build to regenerate.');
        }

        $contents = (string) file_get_contents($this->indexPath);

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $body->write($contents);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}

