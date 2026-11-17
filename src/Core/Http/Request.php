<?php

declare(strict_types=1);

namespace App\Core\Http;

readonly class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $body = [],
        private array $headers = [],
        private array $params = [],
    ) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getBody(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function getHeader(string $key): ?string
    {
        return $this->headers[strtolower($key)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getParam(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function setParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $params);
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $query = $_GET ?? [];
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey = strtolower(substr($key, 5));
                $headers[$headerKey] = $value;
            }
        }

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if ($method !== 'GET') {
            if (str_contains($contentType, 'application/json')) {
                $input = file_get_contents('php://input');
                $body = $input ? json_decode($input, true) ?? [] : [];
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                $body = $_POST ?? [];
            }
        }

        return new self($method, $path, $query, $body, $headers);
    }
}
