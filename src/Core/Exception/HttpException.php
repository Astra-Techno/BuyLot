<?php

declare(strict_types=1);

namespace App\Core\Exception;

class HttpException extends \Exception
{
    public function __construct(
        string $message,
        int $code = 500,
        ?\Throwable $previous = null,
        private ?array $details = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }
}
