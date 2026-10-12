<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Mail\MailSettings;
use App\Modules\Files\ClamdScanner;
use App\Modules\Notifications\MailOutbox;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Everything an operator checks first, on one page: release, database,
 * backups and the last restore test, email, file storage and scanning.
 * Each check gets a state: ok, warn or bad, with a plain-language note.
 */
final class SystemStatus
{
    public const BACKUP_MAX_AGE_HOURS = 26;
    public const RESTORE_CHECK_MAX_AGE_DAYS = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly Migrator $migrator,
        private readonly MailSettings $mail,
        private readonly MailOutbox $outbox,
        private readonly string $storagePath,
        private readonly string $releaseId,
        private readonly int $quotaBytes,
        private readonly ?ClamdScanner $scanner,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'app' => $this->app(),
            'backup' => $this->backup($now),
            'restore' => $this->restoreCheck($now),
            'mail' => $this->mailCheck(),
            'storage' => $this->storage(),
            'scanner' => $this->scannerCheck(),
        ];
    }

    /** @return array{state: string, note: string, release: string, php: string, database: string, applied: int, pending: list<string>} */
    private function app(): array
    {
        $status = $this->migrator->status();
        $pending = array_keys(array_filter($status, static fn (bool $applied): bool => !$applied));
        $version = (string) $this->db->fetchValue('SELECT VERSION()');

        return [
            'state' => $pending === [] ? 'ok' : 'bad',
            'note' => $pending === [] ? 'All database changes are applied.' : 'Database changes are waiting: run bin/console migrate.',
            'release' => $this->releaseId,
            'php' => PHP_VERSION,
            'database' => $version,
            'applied' => count($status) - count($pending),
            'pending' => $pending,
        ];
    }

    /** @return array<string, mixed> */
    private function backup(DateTimeImmutable $now): array
    {
        $data = $this->readJson('backup-status.json');
        if ($data === null) {
            return ['state' => 'bad', 'note' => 'No backup has run yet. Install the backup timer (deployment guide → Backups).', 'data' => null, 'age_hours' => null];
        }
        $last = self::time($data['last_success_at'] ?? null);
        $age = $last === null ? null : (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 3600);
        $offsite = (string) ($data['last_success_offsite'] ?? '');
        [$state, $note] = match (true) {
            $last === null => ['bad', 'Backups have run but none has succeeded yet: ' . (string) ($data['last_message'] ?? '')],
            $age > self::BACKUP_MAX_AGE_HOURS => ['bad', sprintf('The last good backup is %d hours old. Check: journalctl -u dml-backup', $age)],
            ($data['last_result'] ?? '') === 'failed' => ['bad', 'The last backup run failed: ' . (string) ($data['last_message'] ?? '')],
            str_starts_with($offsite, 'FAILED') => ['warn', 'Backups are made, but copying them off the server failed.'],
            $offsite === 'not configured' => ['warn', 'Backups stay on this server only. Set REMOTE in /etc/dml-lms/backup.conf to copy them elsewhere.'],
            default => ['ok', 'Backups are recent and copied off the server.'],
        };

        return ['state' => $state, 'note' => $note, 'data' => $data, 'age_hours' => $age];
    }

    /** @return array<string, mixed> */
    private function restoreCheck(DateTimeImmutable $now): array
    {
        $data = $this->readJson('restore-check.json');
        if ($data === null) {
            return ['state' => 'bad', 'note' => 'No restore test has run. A backup is only proven by restoring it: enable dml-restore-check.timer.', 'data' => null, 'age_days' => null];
        }
        $at = self::time($data['checked_at'] ?? null);
        $days = $at === null ? null : (int) floor(($now->getTimestamp() - $at->getTimestamp()) / 86400);
        [$state, $note] = match (true) {
            ($data['result'] ?? '') !== 'ok' => ['bad', 'The last restore test failed: ' . (string) ($data['message'] ?? '')],
            $days === null || $days > self::RESTORE_CHECK_MAX_AGE_DAYS => ['warn', 'The last successful restore test is more than a week old.'],
            default => ['ok', 'The latest backup was restored into a scratch database and matched.'],
        };

        return ['state' => $state, 'note' => $note, 'data' => $data, 'age_days' => $days];
    }

    /** @return array{state: string, note: string, driver: string, counts: array<string, int>} */
    private function mailCheck(): array
    {
        $counts = $this->outbox->summary();
        $failed = $counts['failed'] ?? 0;
        $queued = $counts['queued'] ?? 0;
        [$state, $note] = match (true) {
            !$this->mail->deliveryAvailable() => ['warn', 'Email is off: password resets and invitations are not sent (deployment guide → Email).'],
            $failed > 0 => ['bad', sprintf('%d email(s) failed in the last 7 days. Check the mail settings and: bin/console mail:status', $failed)],
            $queued > 20 => ['warn', sprintf('%d emails are waiting. Is the mail:work cron job running?', $queued)],
            default => ['ok', 'Email is being delivered.'],
        };

        return ['state' => $state, 'note' => $note, 'driver' => $this->mail->driver(), 'counts' => $counts];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $schools = array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'bytes' => (int) $r['bytes'],
            'files' => (int) $r['files'],
        ], $this->db->fetchAll(
            'SELECT t.name, COALESCE(SUM(f.size_bytes), 0) AS bytes, COUNT(f.id) AS files
             FROM tenants t LEFT JOIN files f ON f.tenant_id = t.id GROUP BY t.id, t.name ORDER BY bytes DESC LIMIT 50',
        ));
        $free = @disk_free_space($this->storagePath);
        $total = @disk_total_space($this->storagePath);
        $freePercent = $free !== false && $total !== false && $total > 0 ? (int) floor($free * 100 / $total) : null;
        [$state, $note] = match (true) {
            $freePercent !== null && $freePercent < 10 => ['bad', sprintf('Only %d%% of the disk is free.', $freePercent)],
            $freePercent !== null && $freePercent < 20 => ['warn', sprintf('%d%% of the disk is free.', $freePercent)],
            default => ['ok', 'Enough disk space.'],
        };
        foreach ($schools as $school) {
            if ($school['bytes'] > $this->quotaBytes * 0.9) {
                [$state, $note] = [$state === 'bad' ? 'bad' : 'warn', sprintf('%s has used over 90%% of its file storage.', $school['name'])];
            }
        }

        return [
            'state' => $state,
            'note' => $note,
            'schools' => $schools,
            'quota' => $this->quotaBytes,
            'free' => $free === false ? null : (int) $free,
            'total' => $total === false ? null : (int) $total,
        ];
    }

    /** @return array{state: string, note: string} */
    private function scannerCheck(): array
    {
        if ($this->scanner === null) {
            return ['state' => 'warn', 'note' => 'Uploads are not virus-scanned. Install ClamAV and set UPLOAD_SCANNER=clamd (deployment guide → Uploaded files).'];
        }

        return $this->scanner->ping()
            ? ['state' => 'ok', 'note' => 'ClamAV is answering; every upload is scanned.']
            : ['state' => 'bad', 'note' => 'ClamAV is not answering, so uploads are being refused.'];
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $name): ?array
    {
        $path = $this->storagePath . '/' . $name;
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
