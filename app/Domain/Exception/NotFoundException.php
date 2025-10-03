<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Recurso nao encontrado')
    {
        parent::__construct($message, 404);
    }
}
