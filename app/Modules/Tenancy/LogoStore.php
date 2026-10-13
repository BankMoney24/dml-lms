<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\UploadedFile;
use App\Core\Log\Logger;
use App\Core\Validation\ValidationException;
use App\Modules\Files\InfectedFile;
use App\Modules\Files\ScannerUnavailable;
use App\Modules\Files\VirusScanner;
use finfo;
use RuntimeException;

/**
 * School logos. Only PNG, JPEG and WebP pictures (never SVG, which can
 * carry script), up to 512 KB, checked by their actual contents, scanned
 * like any upload, and kept with the other uploads (so backups include
 * them) under uploads/<school>/brand/. The logo is public: it appears in
 * the header before anyone signs in.
 */
final class LogoStore
{
    public const MAX_BYTES = 524288;

    /** getimagesize type => [extension, MIME type] */
    private const TYPES = [
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    private const KEY_PATTERN = '#^\d+/brand/[a-f0-9]{32}\.(png|jpg|webp)$#';

    public function __construct(
        private readonly string $uploadRoot,
        private readonly VirusScanner $scanner,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{key: string, type: string}
     * @throws ValidationException on the "logo" field
     */
    public function store(int $tenantId, UploadedFile $upload): array
    {
        if (!$upload->isOk()) {
            throw ValidationException::single('logo', $upload->errorMessage(1));
        }
        $size = (int) filesize($upload->tmpPath);
        if ($size > self::MAX_BYTES) {
            throw ValidationException::single('logo', 'The logo is too large. Use a picture under 512 KB (a PNG about 400 pixels wide is plenty).');
        }
        $info = @getimagesize($upload->tmpPath);
        $type = is_array($info) ? $info[2] : null;
        if (!is_int($type) || !isset(self::TYPES[$type])) {
            throw ValidationException::single('logo', 'Use a PNG, JPEG or WebP picture for the logo.');
        }
        [$extension, $mime] = self::TYPES[$type];
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($upload->tmpPath);
        if ($detected !== $mime) {
            throw ValidationException::single('logo', 'That file does not look like a real picture. Save it again as PNG or JPEG and try once more.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 32 || $height < 32 || $width > 4000 || $height > 4000) {
            throw ValidationException::single('logo', 'The logo should be between 32 and 4000 pixels on each side.');
        }
        try {
            $this->scanner->scan($upload->tmpPath);
        } catch (InfectedFile) {
            throw ValidationException::single('logo', 'The picture was refused: the virus scanner flagged it as unsafe.');
        } catch (ScannerUnavailable $e) {
            $this->logger->error('Logo refused: virus scanner unavailable', ['error' => $e->getMessage()]);
            throw ValidationException::single('logo', 'Files cannot be checked for viruses right now, so the logo cannot be accepted. Please try again later.');
        }

        $key = sprintf('%d/brand/%s.%s', $tenantId, bin2hex(random_bytes(16)), $extension);
        $path = $this->uploadRoot . '/' . $key;
        $this->ensureDirectory(dirname($path));
        $upload->moveTo($path);
        @chmod($path, 0640);

        return ['key' => $key, 'type' => $mime];
    }

    /** Absolute path, or null if the key is malformed or the file is gone. */
    public function path(?string $key): ?string
    {
        if ($key === null || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }
        $path = $this->uploadRoot . '/' . $key;

        return is_file($path) ? $path : null;
    }

    /** True only for a key under this school's own folder. */
    public static function belongsTo(?string $key, int $tenantId): bool
    {
        return $key !== null && preg_match(self::KEY_PATTERN, $key) === 1 && str_starts_with($key, $tenantId . '/brand/');
    }

    public function delete(?string $key): void
    {
        $path = $this->path($key);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $this->ensureDirectory(dirname($dir));
        if (!@mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the logo folder.');
        }
        @chmod($dir, 02770);
    }
}
