<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

final class Notification
{
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $link,
        public readonly string $createdAt,
        public readonly ?string $readAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['kind'],
            (string) $row['title'],
            isset($row['body']) ? (string) $row['body'] : null,
            isset($row['link']) ? (string) $row['link'] : null,
            (string) $row['created_at'],
            isset($row['read_at']) ? (string) $row['read_at'] : null,
        );
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
