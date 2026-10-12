<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config;

/**
 * Reads MAIL_* configuration and builds the mailer.
 *
 *   MAIL_DRIVER=smtp      real delivery through MAIL_HOST
 *   MAIL_DRIVER=file      writes .eml files to storage/mail (default; development and until a provider is set)
 *   MAIL_DRIVER=disabled  no email features at all (admins hand out one-time passwords)
 *
 * Features that depend on email (reset links, invitations) are offered only
 * when delivery is real, or in non-production environments where the file
 * driver lets people test the flows.
 */
final class MailSettings
{
    public function __construct(private readonly Config $config, private readonly string $storagePath)
    {
    }

    public function driver(): string
    {
        return strtolower($this->config->get('MAIL_DRIVER', 'file') ?? 'file');
    }

    public function deliveryAvailable(): bool
    {
        $driver = $this->driver();
        if ($driver === 'disabled') {
            return false;
        }

        return $driver === 'smtp' || !$this->config->isProduction();
    }

    public function mime(): MimeBuilder
    {
        return new MimeBuilder(
            $this->config->get('MAIL_FROM_ADDRESS', 'no-reply@localhost') ?? 'no-reply@localhost',
            $this->config->get('MAIL_FROM_NAME', 'DML Learning Platform') ?? 'DML Learning Platform',
        );
    }

    public function mailer(): Mailer
    {
        if ($this->driver() !== 'smtp') {
            return new FileMailer($this->mime(), $this->storagePath . '/mail');
        }
        $encryption = strtolower($this->config->get('MAIL_ENCRYPTION', 'tls') ?? 'tls');

        return new SmtpMailer(
            $this->mime(),
            $this->config->require('MAIL_HOST'),
            $this->config->int('MAIL_PORT', $encryption === 'ssl' ? 465 : 587),
            in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
            $this->config->get('MAIL_USERNAME'),
            $this->config->get('MAIL_PASSWORD'),
            $this->config->bool('MAIL_VERIFY_PEER', true),
            $this->config->int('MAIL_TIMEOUT', 15),
        );
    }
}
