<?php

declare(strict_types=1);

namespace App\Core\Exception;

class ValidationException extends HttpException
{
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422, details: $errors);
    }

    public function getErrors(): array
    {
        return $this->details ?? [];
    }
}
