<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * One file from a multipart form (or, for imports and tests, a local file
 * presented the same way). The client's name and type are only hints; the
 * upload policy decides what the file really is from its contents.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $fromHttpUpload = true,
    ) {
    }

    /** A file already on disk (course packages, tests). */
    public static function fromLocalPath(string $path, ?string $name = null): self
    {
        $size = is_file($path) ? filesize($path) : false;

        return new self($name ?? basename($path), $path, $size === false ? 0 : $size, $size === false ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK, false);
    }

    public function wasSent(): bool
    {
        return $this->error !== UPLOAD_ERR_NO_FILE;
    }

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK && ($this->fromHttpUpload ? is_uploaded_file($this->tmpPath) : is_file($this->tmpPath));
    }

    /** Plain-language reason a failed upload did not arrive. */
    public function errorMessage(int $maxMegabytes): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf('“%s” is too big. Files can be up to %d MB.', $this->clientName, $maxMegabytes),
            UPLOAD_ERR_PARTIAL => sprintf('“%s” only partly arrived; the connection may have dropped. Please try again.', $this->clientName),
            UPLOAD_ERR_NO_FILE => 'Choose a file first.',
            default => sprintf('“%s” could not be received. Please try again.', $this->clientName),
        };
    }

    /** Copies the file to its final place. HTTP uploads are moved, local files are copied. */
    public function moveTo(string $destination): void
    {
        $ok = $this->fromHttpUpload
            ? move_uploaded_file($this->tmpPath, $destination)
            : copy($this->tmpPath, $destination);
        if (!$ok) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
    }

    /**
     * Normalises one entry of $_FILES, single or multiple, into a list.
     *
     * @param array<string, mixed> $entry
     * @return list<self>
     */
    public static function listFromPhp(array $entry): array
    {
        $names = $entry['name'] ?? null;
        if (!is_array($names)) {
            return [self::fromPhpParts($entry['name'] ?? '', $entry['tmp_name'] ?? '', $entry['size'] ?? 0, $entry['error'] ?? UPLOAD_ERR_NO_FILE)];
        }
        $files = [];
        foreach (array_keys($names) as $i) {
            $files[] = self::fromPhpParts(
                $names[$i] ?? '',
                is_array($entry['tmp_name'] ?? null) ? ($entry['tmp_name'][$i] ?? '') : '',
                is_array($entry['size'] ?? null) ? ($entry['size'][$i] ?? 0) : 0,
                is_array($entry['error'] ?? null) ? ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
            );
        }

        return $files;
    }

    private static function fromPhpParts(mixed $name, mixed $tmp, mixed $size, mixed $error): self
    {
        return new self(
            is_string($name) ? $name : '',
            is_string($tmp) ? $tmp : '',
            is_numeric($size) ? (int) $size : 0,
            is_numeric($error) ? (int) $error : UPLOAD_ERR_NO_FILE,
        );
    }
}
