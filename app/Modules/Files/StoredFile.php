<?php

declare(strict_types=1);

namespace App\Modules\Files;

final class StoredFile
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly ?int $lessonId,
        public readonly ?int $submissionId,
        public readonly int $uploadedBy,
        public readonly string $originalName,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly string $storageKey,
        public readonly string $scanStatus,
        public readonly string $createdAt,
        public readonly ?int $supportMessageId = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            $row['lesson_id'] === null ? null : (int) $row['lesson_id'],
            $row['submission_id'] === null ? null : (int) $row['submission_id'],
            (int) $row['uploaded_by'],
            (string) $row['original_name'],
            (string) $row['extension'],
            (string) $row['mime_type'],
            (int) $row['size_bytes'],
            (string) $row['sha256'],
            (string) $row['storage_key'],
            (string) $row['scan_status'],
            (string) $row['created_at'],
            isset($row['support_message_id']) ? (int) $row['support_message_id'] : null,
        );
    }

    /** "2.4 MB", "830 KB": shown beside every link so learners know what it costs to download. */
    public function humanSize(): string
    {
        return self::formatBytes($this->sizeBytes);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, $bytes >= 107374182400 ? 0 : 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, $bytes >= 10485760 ? 0 : 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return max(1, (int) round($bytes / 1024)) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /** Up to four letters for the small file badge. */
    public function iconLabel(): string
    {
        return match ($this->extension) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'IMG',
            'docx', 'odt' => 'DOC',
            'xlsx', 'ods' => 'XLS',
            'pptx', 'odp' => 'PPT',
            'service', 'timer' => 'UNIT',
            'local', 'cnf', 'cfg', 'ini', 'conf' => 'CONF',
            default => strlen($this->extension) <= 4 ? strtoupper($this->extension) : 'TXT',
        };
    }

    public function kindLabel(): string
    {
        return UploadPolicy::label($this->extension);
    }
}
