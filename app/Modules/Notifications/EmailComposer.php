<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Mail\Message;
use App\Core\View\View;

/**
 * Renders an email from two templates: resources/views/emails/<name>.php
 * (HTML, inside emails/layout) and emails/<name>_text.php (plain text).
 * Values are escaped by the view helpers as in any page.
 */
final class EmailComposer
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, mixed> $data */
    public function compose(string $template, string $toEmail, ?string $toName, string $subject, array $data): Message
    {
        $data += ['subject' => $subject];

        return new Message(
            $toEmail,
            $toName,
            $subject,
            trim($this->view->render('emails/' . $template . '_text', $data, null)) . "\n",
            $this->view->render('emails/' . $template, $data, 'emails/layout'),
        );
    }
}
