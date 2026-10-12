<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Log\Logger;
use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use Throwable;

/**
 * Durable email queue (NTF-002). Messages are stored first, so a slow or
 * failing provider never loses an email or blocks a page. Delivery happens
 * right after the response is sent and by the `mail:work` command, with
 * exponential backoff. Bodies (which may hold single-use links) are erased
 * once a message is sent or abandoned.
 */
final class MailOutbox
{
    /** Seconds to wait before attempt n+1. After the last entry the message is marked failed. */
    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    /** @var list<int> */
    private array $queuedThisRequest = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function queue(?int $tenantId, Message $message): int
    {
        $now = $this->clock->sqlNow();
        $id = $this->db->insert(
            'INSERT INTO mail_outbox (tenant_id, to_email, to_name, subject, text_body, html_body, status, attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'queued\', 0, ?, ?)',
            [$tenantId, $message->toEmail, $message->toName, $message->subject, $message->text, $message->html, $now, $now],
        );
        $this->queuedThisRequest[] = $id;

        return $id;
    }

    /** Delivers what this request queued; called after the response has gone to the browser. */
    public function deliverQueuedThisRequest(): void
    {
        $ids = $this->queuedThisRequest;
        $this->queuedThisRequest = [];
        foreach ($ids as $id) {
            $this->deliver($id);
        }
    }

    /** @return array{sent: int, retrying: int, failed: int} */
    public function deliverDue(int $limit = 50): array
    {
        // A worker that died mid-send leaves rows in "sending"; give them back after 10 minutes.
        $this->db->execute(
            "UPDATE mail_outbox SET status = 'queued' WHERE status = 'sending' AND next_attempt_at < ?",
            [$this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s')],
        );
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->fetchAll(
                "SELECT id FROM mail_outbox WHERE status = 'queued' AND next_attempt_at <= ? ORDER BY id LIMIT ?",
                [$this->clock->sqlNow(), max(1, $limit)],
            ),
        );
        $result = ['sent' => 0, 'retrying' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $outcome = $this->deliver($id);
            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /** @return array<string, int> status => count, for the last 7 days */
    public function summary(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS n FROM mail_outbox WHERE created_at >= ? GROUP BY status',
            [$this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')],
        );
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row['status']] = (int) $row['n'];
        }

        return $summary;
    }

    /** @return 'sent'|'retrying'|'failed'|null null when another worker already claimed it */
    private function deliver(int $id): ?string
    {
        // Atomic claim: only one process sends a given message.
        $claimed = $this->db->execute(
            "UPDATE mail_outbox SET status = 'sending', attempts = attempts + 1, next_attempt_at = ? WHERE id = ? AND status = 'queued'",
            [$this->clock->sqlNow(), $id],
        );
        if ($claimed !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }

        try {
            $this->mailer->send(new Message(
                (string) $row['to_email'],
                $row['to_name'] === null ? null : (string) $row['to_name'],
                (string) $row['subject'],
                (string) $row['text_body'],
                $row['html_body'] === null ? null : (string) $row['html_body'],
            ));
            $this->db->execute(
                "UPDATE mail_outbox SET status = 'sent', sent_at = ?, last_error = NULL, text_body = NULL, html_body = NULL WHERE id = ?",
                [$this->clock->sqlNow(), $id],
            );

            return 'sent';
        } catch (Throwable $e) {
            $attempts = (int) $row['attempts'];
            $permanent = $e instanceof MailException && $e->permanent;
            $error = mb_substr($e instanceof MailException ? $e->getMessage() : 'Unexpected error while sending.', 0, 500);
            $this->logger->error('Email delivery failed', ['outbox_id' => $id, 'attempt' => $attempts, 'permanent' => $permanent], $e);

            if ($permanent || $attempts > count(self::BACKOFF)) {
                $this->db->execute(
                    "UPDATE mail_outbox SET status = 'failed', last_error = ?, text_body = NULL, html_body = NULL WHERE id = ?",
                    [$error, $id],
                );

                return 'failed';
            }
            $next = $this->clock->now()->modify('+' . self::BACKOFF[$attempts - 1] . ' seconds')->format('Y-m-d H:i:s');
            $this->db->execute(
                "UPDATE mail_outbox SET status = 'queued', last_error = ?, next_attempt_at = ? WHERE id = ?",
                [$error, $next, $id],
            );

            return 'retrying';
        }
    }
}
