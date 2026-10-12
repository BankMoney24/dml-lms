<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /** Streamed from disk instead of $body (downloads). */
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        private array $headers = [],
    ) {
    }

    /**
     * Sends a stored file. The name is offered to the browser in both an
     * ASCII fallback and the exact UTF-8 form (RFC 6266 / 5987).
     */
    public static function file(string $path, string $contentType, string $downloadName, bool $inline): self
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $downloadName);
        $disposition = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $ascii === '' ? 'download' : $ascii,
            rawurlencode($downloadName),
        );
        $size = filesize($path);
        $response = new self('', 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) ($size === false ? 0 : $size),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->filePath = $path;

        return $response;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Only same-site relative redirects are produced by the app; reject anything else.
        if (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            $location = '/';
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);

            return;
        }
        echo $this->body;
    }
}
