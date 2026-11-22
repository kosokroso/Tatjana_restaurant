<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\AppException;

/**
 * Nespremenljiv zahtevek. Iz globalnih spremenljivk se sestavi samo v
 * fromGlobals(); testi ga sestavijo neposredno.
 */
final class Request
{
    public const MAX_BODY_BYTES = 16384;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers  imena z malimi črkami
     * @param array<string, string> $cookies
     * @param array<string, string> $params   parametri poti (nastavi Router)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $rawBody = '',
        public readonly array $cookies = [],
        public readonly string $ip = '-',
        public readonly bool $https = false,
        public readonly array $params = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($headers['x-forwarded-proto'] ?? '') === 'https';

        // Branje omejeno: preveliko telo ne sme v pomnilnik.
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            array_filter($_GET, 'is_string'),
            $headers,
            $raw,
            array_filter($_COOKIE, 'is_string'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            $https,
        );
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers,
            $this->rawBody, $this->cookies, $this->ip, $this->https, $params);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function intParam(string $name): int
    {
        return (int) $this->param($name);
    }

    /**
     * Telo kot JSON objekt. Prazno telo je prazen objekt.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if (strlen($this->rawBody) > self::MAX_BODY_BYTES) {
            throw new AppException('too_large', 'Zahtevek je prevelik.', 413);
        }
        if (trim($this->rawBody) === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw AppException::invalid([], 'Telo zahtevka ni veljaven JSON objekt.');
        }
        return $data;
    }
}
