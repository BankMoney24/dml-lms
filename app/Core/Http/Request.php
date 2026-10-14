<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Immutable-ish HTTP request. Route parameters and per-request context
 * (tenant, user, request id) are attached as attributes by middleware.
 */
final class Request
{
    /** JSON bodies (webhooks) larger than this are not read. */
    public const MAX_RAW_BYTES = 65536;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers lower-cased header names
     * @param array<string, string> $cookies
     * @param array<string, list<UploadedFile>> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $host,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        public readonly string $clientIp = '0.0.0.0',
        public readonly bool $secure = false,
        private readonly array $files = [],
        public readonly bool $bodyTooLarge = false,
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $files = [];
        foreach ($_FILES as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $files[$key] = UploadedFile::listFromPhp($entry);
            }
        }
        // When a form body exceeds post_max_size PHP silently drops all fields and files.
        // Only form posts are parsed into $_POST; a JSON body (a webhook) never is.
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $type = strtolower($headers['content-type'] ?? '');
        $isForm = str_starts_with($type, 'application/x-www-form-urlencoded') || str_starts_with($type, 'multipart/form-data');
        $tooLarge = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $isForm && $length > 0 && $_POST === [] && $_FILES === [];
        $raw = '';
        if (str_starts_with($type, 'application/json') && $length > 0 && $length <= self::MAX_RAW_BYTES) {
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_RAW_BYTES);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: self::normalisePath(is_string($path) ? $path : '/'),
            host: self::normaliseHost((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')),
            query: $_GET,
            body: $_POST,
            headers: $headers,
            cookies: array_map('strval', $_COOKIE),
            clientIp: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            secure: ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
            files: $files,
            bodyTooLarge: $tooLarge,
            rawBody: $raw,
        );
    }

    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        // Strip port; hosts are matched against verified tenant domains only.
        $host = preg_replace('/:\d+$/', '', $host) ?? '';

        return rtrim($host, '.');
    }

    public static function normalisePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Scheme, host and port this request arrived on, for links in emails.
     * The host has already been matched to a verified tenant domain; the
     * port is taken from the Host header only if it is numeric.
     */
    public function baseUrl(): string
    {
        $port = '';
        $raw = strtolower(trim($this->header('host') ?? ''));
        if (preg_match('/:(\d{1,5})$/', $raw, $m) === 1 && self::normaliseHost($raw) === $this->host) {
            $port = ':' . $m[1];
        }
        $scheme = $this->secure ? 'https' : 'http';
        if (($scheme === 'https' && $port === ':443') || ($scheme === 'http' && $port === ':80')) {
            $port = '';
        }

        return $scheme . '://' . $this->host . $port;
    }

    /** The query string as received (re-encoded), for redirects that must keep it. */
    public function queryString(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Returns a nested array field (e.g. answers[12]=34) as string => string.
     *
     * @return array<string, string>
     */
    public function inputArray(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v)) {
                $clean[(string) $k] = (string) $v;
            }
        }

        return $clean;
    }

    /** @return list<UploadedFile> files actually chosen in this field (empty slots dropped) */
    public function files(string $key): array
    {
        return array_values(array_filter($this->files[$key] ?? [], static fn (UploadedFile $f): bool => $f->wasSent()));
    }

    public function file(string $key): ?UploadedFile
    {
        return $this->files($key)[0] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function routeParam(string $name): string
    {
        $params = $this->attributes['route.params'] ?? [];

        return is_array($params) && isset($params[$name]) ? (string) $params[$name] : '';
    }

    public function routeInt(string $name): int
    {
        $raw = $this->routeParam($name);

        return ctype_digit($raw) ? (int) $raw : 0;
    }
}
