<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public static function ok(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, ['ok' => true, 'data' => $data], $headers);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>  $extra
     */
    public static function error(int $status, string $code, string $message, array $fields = [], array $extra = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return new self($status, ['ok' => false, 'error' => $error + $extra]);
    }

    public static function notModified(string $etag): self
    {
        return new self(304, null, ['ETag' => $etag]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [$name => $value] + $this->headers);
    }

    /** Doda request_id v telo; Kernel ga kliče tik pred pošiljanjem. */
    public function withRequestId(string $id): self
    {
        $body = $this->body === null ? null : $this->body + ['request_id' => $id];
        return new self($this->status, $body, ['X-Request-Id' => $id] + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        if ($this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
