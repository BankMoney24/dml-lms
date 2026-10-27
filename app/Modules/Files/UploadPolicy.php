<?php

declare(strict_types=1);

namespace App\Modules\Files;

use finfo;

/**
 * Which files may be uploaded, judged by extension AND by what the contents
 * actually are (libmagic via finfo). A file whose contents do not match its
 * extension is refused, so "notes.pdf" that is really a program never gets in.
 *
 * How each kind is served back:
 *   - PDFs, photos, video and audio open in the browser (media streams in
 *     byte ranges, so a learner can seek without downloading it all);
 *   - text and code are shown as plain text, never run (text/plain, nosniff,
 *     CSP sandbox), so a learner's .html or .php is displayed, not executed;
 *   - everything else is a download.
 */
final class UploadPolicy
{
    private const TEXT = 'text';
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** extension => [label, accepted detected types (TEXT = any text), served type, inline] */
    private const KINDS = [
        'pdf' => ['PDF', ['application/pdf'], 'application/pdf', true],
        'png' => ['Image', ['image/png'], 'image/png', true],
        'jpg' => ['Image', ['image/jpeg'], 'image/jpeg', true],
        'jpeg' => ['Image', ['image/jpeg'], 'image/jpeg', true],
        'gif' => ['Image', ['image/gif'], 'image/gif', true],
        'webp' => ['Image', ['image/webp'], 'image/webp', true],
        'zip' => ['Zip archive', ['application/zip'], 'application/zip', false],
        // libmagic sometimes reports Office files as a plain zip.
        'docx' => ['Word document', [self::DOCX, 'application/zip'], self::DOCX, false],
        'xlsx' => ['Spreadsheet', [self::XLSX, 'application/zip'], self::XLSX, false],
        'pptx' => ['Slides', [self::PPTX, 'application/zip'], self::PPTX, false],
        'odt' => ['Document', ['application/vnd.oasis.opendocument.text', 'application/zip'], 'application/vnd.oasis.opendocument.text', false],
        'ods' => ['Spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'], 'application/vnd.oasis.opendocument.spreadsheet', false],
        'odp' => ['Slides', ['application/vnd.oasis.opendocument.presentation', 'application/zip'], 'application/vnd.oasis.opendocument.presentation', false],
        // Cisco Packet Tracer networks (.pkt) and activities (.pka), ADR 0035. Their contents are
        // scrambled, so libmagic sees unknown binary; anything it recognises (a program, an archive) is refused.
        'pkt' => ['Packet Tracer network', ['application/octet-stream'], 'application/octet-stream', false],
        'pka' => ['Packet Tracer activity', ['application/octet-stream'], 'application/octet-stream', false],
        'txt' => ['Text', [self::TEXT], 'text/plain', true],
        'md' => ['Text', [self::TEXT], 'text/plain', true],
        'log' => ['Log', [self::TEXT], 'text/plain', true],
        'csv' => ['CSV', [self::TEXT], 'text/plain', true],
        'conf' => ['Config file', [self::TEXT], 'text/plain', true],
        'cfg' => ['Config file', [self::TEXT], 'text/plain', true],
        'ini' => ['Config file', [self::TEXT], 'text/plain', true],
        'cnf' => ['Config file', [self::TEXT], 'text/plain', true],
        'local' => ['Config file', [self::TEXT], 'text/plain', true],
        'yml' => ['YAML', [self::TEXT], 'text/plain', true],
        'yaml' => ['YAML', [self::TEXT], 'text/plain', true],
        'json' => ['JSON', [self::TEXT, 'application/json'], 'text/plain', true],
        'xml' => ['XML', [self::TEXT], 'text/plain', true],
        'sql' => ['SQL', [self::TEXT], 'text/plain', true],
        'sh' => ['Shell script', [self::TEXT], 'text/plain', true],
        'py' => ['Python', [self::TEXT], 'text/plain', true],
        'php' => ['PHP', [self::TEXT], 'text/plain', true],
        'js' => ['JavaScript', [self::TEXT, 'application/javascript'], 'text/plain', true],
        'css' => ['CSS', [self::TEXT], 'text/plain', true],
        'html' => ['HTML', [self::TEXT], 'text/plain', true],
        'service' => ['systemd unit', [self::TEXT], 'text/plain', true],
        'timer' => ['systemd unit', [self::TEXT], 'text/plain', true],
        // Video and audio (ADR 0029). Only formats every current phone browser
        // plays; .mov and other camera formats should be exported as MP4.
        'mp4' => ['Video', ['video/mp4', 'video/x-m4v', 'video/quicktime'], 'video/mp4', true],
        'webm' => ['Video', ['video/webm', 'audio/webm'], 'video/webm', true],
        'mp3' => ['Audio', ['audio/mpeg', 'audio/mp3', 'audio/x-mp3'], 'audio/mpeg', true],
        'm4a' => ['Audio', ['audio/x-m4a', 'audio/mp4', 'video/mp4'], 'audio/mp4', true],
        'ogg' => ['Audio', ['audio/ogg', 'application/ogg', 'video/ogg'], 'audio/ogg', true],
        'opus' => ['Audio', ['audio/ogg', 'application/ogg'], 'audio/ogg', true],
        'wav' => ['Audio', ['audio/x-wav', 'audio/wav', 'audio/vnd.wave'], 'audio/wav', true],
    ];

    private const VIDEO = ['mp4', 'webm'];
    private const AUDIO = ['mp3', 'm4a', 'ogg', 'opus', 'wav'];

    public readonly int $mediaMaxBytes;

    /** @param int|null $mediaMaxBytes limit for video and audio; other files use $maxBytes */
    public function __construct(public readonly int $maxBytes, ?int $mediaMaxBytes = null)
    {
        $this->mediaMaxBytes = max($maxBytes, $mediaMaxBytes ?? $maxBytes);
    }

    public function maxMegabytes(): int
    {
        return (int) floor($this->maxBytes / 1048576);
    }

    public function mediaMaxMegabytes(): int
    {
        return (int) floor($this->mediaMaxBytes / 1048576);
    }

    /**
     * The most a browser upload can really be: our limit, or PHP's
     * upload_max_filesize / post_max_size if those are lower. Used for the
     * limits people are told and the checks made before uploading.
     */
    public function webLimit(bool $media): int
    {
        $limit = $media ? $this->mediaMaxBytes : $this->maxBytes;
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limit = min($limit, $setting === 'post_max_size' ? max(1, $bytes - 65536) : $bytes);
            }
        }

        return $limit;
    }

    public function webMegabytes(bool $media): int
    {
        return max(1, (int) floor($this->webLimit($media) / 1048576));
    }

    /** "200M" => 209715200; "0" or nonsense => 0 (no limit). */
    public static function iniBytes(string $value): int
    {
        if (preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
    }

    public function limitFor(string $extension): int
    {
        return self::mediaKind($extension) === null ? $this->maxBytes : $this->mediaMaxBytes;
    }

    /** 'video', 'audio' or null for any other kind of file. */
    public static function mediaKind(string $extension): ?string
    {
        return match (true) {
            in_array($extension, self::VIDEO, true) => 'video',
            in_array($extension, self::AUDIO, true) => 'audio',
            default => null,
        };
    }

    /** For a picker that only takes media: ".mp4,.webm" for video, all media otherwise. */
    public static function mediaAcceptAttribute(?string $kind = null): string
    {
        $list = match ($kind) {
            'video' => self::VIDEO,
            'audio' => self::AUDIO,
            default => [...self::VIDEO, ...self::AUDIO],
        };

        return implode(',', array_map(static fn (string $e): string => '.' . $e, $list));
    }

    public static function extensionOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function isAllowedExtension(string $extension): bool
    {
        return isset(self::KINDS[$extension]);
    }

    public static function label(string $extension): string
    {
        return self::KINDS[$extension][0] ?? strtoupper($extension);
    }

    /** For the file picker's accept attribute; $media false leaves out video and audio. */
    public static function acceptAttribute(bool $media = true): string
    {
        $extensions = array_keys(self::KINDS);
        if (!$media) {
            $extensions = array_values(array_filter($extensions, static fn (string $e): bool => self::mediaKind($e) === null));
        }

        return implode(',', array_map(static fn (string $e): string => '.' . $e, $extensions));
    }

    /** "PDF, images, Office and OpenDocument files, zip, and text or code files". */
    public static function summary(): string
    {
        return 'PDF, images, Word/Excel/PowerPoint and OpenDocument files, zip archives, text or code files, Cisco Packet Tracer files (.pkt, .pka), video (MP4, WebM) and audio (MP3, M4A, OGG, WAV)';
    }

    /**
     * Checks the real contents. Returns the problem in plain words, or null when acceptable.
     */
    public function problemWith(string $path, string $extension): ?string
    {
        if (!isset(self::KINDS[$extension])) {
            return 'This kind of file is not accepted. Allowed: ' . self::summary() . '.';
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return 'The file is empty.';
        }
        if ($size > $this->limitFor($extension)) {
            return self::mediaKind($extension) === null
                ? sprintf('The file is too big. Files can be up to %d MB.', $this->maxMegabytes())
                : sprintf('The file is too big. Video and audio can be up to %d MB; try a lower quality or a shorter clip.', $this->mediaMaxMegabytes());
        }
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $accepted = self::KINDS[$extension][1];
        if (in_array(self::TEXT, $accepted, true) && $this->isText($path, (string) $detected)) {
            return null;
        }
        if (is_string($detected) && in_array($detected, $accepted, true)) {
            return null;
        }

        return sprintf('The file’s contents do not match its name (.%s). Save it again in the right format and retry.', $extension);
    }

    /** Type sent when the file is downloaded. Never anything a browser would execute. */
    public static function servedType(string $extension): string
    {
        $type = self::KINDS[$extension][2] ?? 'application/octet-stream';

        return $type === 'text/plain' ? 'text/plain; charset=utf-8' : $type;
    }

    /** Files that open in Cisco Packet Tracer, which learners install themselves. */
    public static function isPacketTracer(string $extension): bool
    {
        return $extension === 'pkt' || $extension === 'pka';
    }

    public static function opensInBrowser(string $extension): bool
    {
        return self::KINDS[$extension][3] ?? false;
    }

    /** Text: libmagic says text (or empty-ish JSON), valid UTF-8, no NUL bytes. */
    private function isText(string $path, string $detected): bool
    {
        if (!str_starts_with($detected, 'text/') && !in_array($detected, ['application/json', 'application/javascript', 'application/x-wine-extension-ini'], true)) {
            return false;
        }
        $sample = (string) file_get_contents($path, false, null, 0, 1048576);
        if (strlen($sample) === 1048576) {
            $sample = mb_strcut($sample, 0, 1048572, 'UTF-8'); // do not judge a character cut in half
        }

        return !str_contains($sample, "\0") && mb_check_encoding($sample, 'UTF-8');
    }
}
