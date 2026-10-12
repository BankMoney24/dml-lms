<?php

declare(strict_types=1);

namespace App\Core\Mail;

interface Mailer
{
    /** @throws MailException when the message was not accepted for delivery */
    public function send(Message $message): void;
}
