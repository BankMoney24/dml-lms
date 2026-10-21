<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /**
     * An open-ended range ("bytes=1000-") is answered with at most this much.
     * Players then ask for the next piece only when they need it, so a learner
     * who stops watching does not keep downloading, and no PHP worker is tied
     * up for the whole length of a video.
     */
    public const MAX_OPEN_RANGE = 4194304;

    /** Streamed from disk instead of $body (downloads). */
    private ?string $filePath = null;

    /** @var array{0: int, 1: int}|null first and last byte to send, for a range request */
    private ?array $byteRange = null;

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

    /**
     * Answers an HTTP Range request for a file (RFC 9110 §14), so video and
     * audio can be seeked and resumed without downloading everything: a single
     * "bytes=a-b", "bytes=a-" or "bytes=-n" gets 206 with that part; a range
     * that cannot be met gets 416; anything else (several ranges, other units,
     * nonsense) gets the whole file, which the standard allows. An open-ended
     * range gets at most MAX_OPEN_RANGE bytes, which the standard also allows.
     */
    public function forRange(?string $range): self
    {
        if ($this->filePath === null) {
            return $this;
        }
        $clone = $this->withHeader('Accept-Ranges', 'bytes');
        if ($range === null || preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $range, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return $clone;
        }
        $size = (int) filesize($this->filePath);
        if ($m[1] === '') {
            $length = min((int) $m[2], $size);
            [$first, $last] = [$size - $length, $size - 1];
            if ($length === 0) {
                return $clone->unsatisfiable($size);
            }
        } else {
            $first = (int) $m[1];
            $last = $m[2] === '' ? min($size - 1, $first + self::MAX_OPEN_RANGE - 1) : min((int) $m[2], $size - 1);
            if ($first >= $size || $first > $last) {
                return $clone->unsatisfiable($size);
            }
        }
        $partial = new self('', 206, $clone->headers);
        $partial->filePath = $this->filePath;
        $partial->byteRange = [$first, $last];
        $partial->headers['Content-Range'] = sprintf('bytes %d-%d/%d', $first, $last, $size);
        $partial->headers['Content-Length'] = (string) ($last - $first + 1);

        return $partial;
    }

    /** @return array{0: int, 1: int}|null */
    public function byteRange(): ?array
    {
        return $this->byteRange;
    }

    private function unsatisfiable(int $size): self
    {
        $headers = $this->headers;
        unset($headers['Content-Disposition'], $headers['Content-Type']);
        $headers['Content-Range'] = 'bytes */' . $size;
        $headers['Content-Length'] = '0';

        return new self('', 416, $headers);
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

    /**
     * A permanent move to the school's canonical address. The target is built
     * from a verified domain in the database, never from request input; it
     * must be an absolute http(s) URL to a plain host name.
     */
    public static function toCanonical(string $url): self
    {
        if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?/[^\s]*$#', $url) !== 1) {
            throw new \InvalidArgumentException('Not a canonical address.');
        }

        return new self('', 301, ['Location' => $url]);
    }

    /**
     * Sends the browser to a payment provider's checkout page. Only exact,
     * known checkout addresses are allowed, so this can never become an
     * open redirect.
     */
    public static function redirectToCheckout(string $url): self
    {
        if (preg_match('#^https://checkout\.paystack\.com/[A-Za-z0-9]+$#', $url) !== 1) {
            throw new \InvalidArgumentException('Not a known checkout address.');
        }

        return new self('', 303, ['Location' => $url]);
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
            $this->stream($this->filePath);

            return;
        }
        echo $this->body;
    }

    /**
     * Sends a file (or the asked-for part) in pieces. The session is released
     * first: a long video must not hold the learner's session lock and stall
     * every other page they open meanwhile.
     */
    private function stream(string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        [$first, $last] = $this->byteRange ?? [0, max(0, (int) filesize($path) - 1)];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        if (($last - $first) > 8388608) {
            set_time_limit(0); // slow connections; PHP stops anyway once the browser goes away
        }
        fseek($handle, $first);
        $left = $last - $first + 1;
        while ($left > 0 && !feof($handle)) {
            $chunk = fread($handle, min(262144, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            if (connection_aborted() === 1) {
                break;
            }
        }
        fclose($handle);
    }
}
