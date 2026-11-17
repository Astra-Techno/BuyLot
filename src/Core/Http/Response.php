<?php

declare(strict_types=1);

namespace App\Core\Http;

use JsonSerializable;

readonly class Response implements JsonSerializable
{
    private const MESSAGES = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public function __construct(
        private mixed $data = null,
        private int $statusCode = 200,
        private array $headers = [],
        private ?string $message = null,
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getStatusMessage(): string
    {
        return $this->message ?? self::MESSAGES[$this->statusCode] ?? 'Unknown';
    }

    public static function json(mixed $data = null, int $statusCode = 200, array $headers = []): self
    {
        return new self(
            $data,
            $statusCode,
            array_merge(['Content-Type' => 'application/json'], $headers),
        );
    }

    public static function created(mixed $data = null, array $headers = []): self
    {
        return self::json($data, 201, $headers);
    }

    public static function noContent(): self
    {
        return self::json(null, 204);
    }

    public static function badRequest(string $message = null, mixed $data = null): self
    {
        return self::json(
            ['error' => $message ?? 'Bad Request', 'details' => $data],
            400,
        );
    }

    public static function unauthorized(string $message = null): self
    {
        return self::json(['error' => $message ?? 'Unauthorized'], 401);
    }

    public static function forbidden(string $message = null): self
    {
        return self::json(['error' => $message ?? 'Forbidden'], 403);
    }

    public static function notFound(string $message = null): self
    {
        return self::json(['error' => $message ?? 'Not Found'], 404);
    }

    public static function unprocessable(array $errors): self
    {
        return self::json(['errors' => $errors], 422);
    }

    public static function serverError(string $message = null): self
    {
        return self::json(['error' => $message ?? 'Internal Server Error'], 500);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }

        if ($this->data !== null) {
            echo json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->statusCode,
            'message' => $this->getStatusMessage(),
            'data' => $this->data,
        ];
    }
}
