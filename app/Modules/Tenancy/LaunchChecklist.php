<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Database\Connection;
use App\Core\Mail\MailSettings;
use App\Modules\Site\SitePageRepository;
use App\Modules\Site\SitePages;

/**
 * "Ready for learners?" on the school's settings page: what is set up and what is left,
 * each worked out from the school's real settings (nothing here is ticked by hand).
 */
final class LaunchChecklist
{
    /** Website pages every school should publish; the refund policy joins them once fees are taken online. */
    public const CORE_PAGES = ['about', 'contact', 'privacy', 'terms'];

    public function __construct(
        private readonly Connection $db,
        private readonly MailSettings $mail,
        private readonly SitePageRepository $pages,
    ) {
    }

    /**
     * @return list<array{key: string, title: string, done: bool, state: string, href: string, action: string}>
     */
    public function items(Tenant $tenant): array
    {
        $id = $tenant->id;
        $items = [];

        $courses = (int) $this->db->fetchValue("SELECT COUNT(*) FROM courses WHERE tenant_id = ? AND status = 'published'", [$id]);
        $items[] = $this->item(
            'courses',
            'At least one course is published',
            $courses > 0,
            $courses > 0 ? $courses . ' published' : 'No published course yet',
            '/teach',
            $courses > 0 ? 'See courses' : 'Publish a course'
        );

        $teachers = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM memberships WHERE tenant_id = ? AND status = 'active' AND role = 'educator'",
            [$id],
        );
        $items[] = $this->item(
            'teachers',
            'Teachers are added',
            $teachers > 0,
            $teachers > 0 ? $teachers . ' ' . ($teachers === 1 ? 'teacher' : 'teachers') : 'No teachers yet',
            '/people?role=educator',
            $teachers > 0 ? 'See teachers' : 'Add teachers'
        );

        $payments = $this->db->fetchOne('SELECT mode FROM payment_accounts WHERE tenant_id = ?', [$id]);
        $mode = $payments === null ? null : (string) $payments['mode'];
        $items[] = $this->item(
            'payments',
            'Online payments take real money',
            $mode === 'live',
            match ($mode) {
            'live' => 'Live', 'test' => 'Test mode: no real money is taken', default => 'Not connected'
            },
            '/school/payments',
            $mode === 'live' ? 'See payments' : 'Connect the live key'
        );

        $wanted = self::CORE_PAGES;
        if ($mode !== null) {
            $wanted[] = 'refunds';
        }
        $published = array_map(static fn ($p): string => $p->slug, $this->pages->published($id));
        $missing = array_values(array_diff($wanted, $published));
        $names = array_map(static fn (string $slug): string => strtolower(SitePages::PAGES[$slug][0]), $missing);
        $items[] = $this->item(
            'website',
            'Website pages are published',
            $missing === [],
            $missing === [] ? count($wanted) . ' of ' . count($wanted) . ' published' : 'Still to publish: ' . implode(', ', $names),
            '/school/website',
            $missing === [] ? 'See the website' : 'Publish pages'
        );

        $smtp = $this->mail->driver() === 'smtp';
        $items[] = $this->item(
            'email',
            'Emails reach people',
            $smtp,
            $smtp ? 'Sending through the school’s email provider' : 'Not sending real email yet: ask your platform administrator to connect an email provider',
            '/school',
            ''
        );

        $classroom = $this->db->fetchValue('SELECT 1 FROM classroom_settings WHERE tenant_id = ?', [$id]) !== null;
        $items[] = $this->item(
            'classroom',
            'Online classroom is connected',
            $classroom,
            $classroom ? 'Connected' : 'Not connected: live classes use outside meeting links',
            '/school/classroom',
            $classroom ? 'Check it' : 'Connect it'
        );

        $noTwoStep = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? AND m.status = 'active' AND m.role IN ('owner', 'admin') AND u.totp_enabled_at IS NULL",
            [$id],
        );
        $items[] = $this->item(
            'two_step',
            'Every administrator uses two-step sign-in',
            $noTwoStep === 0,
            $noTwoStep === 0 ? 'All on' : $noTwoStep . ' ' . ($noTwoStep === 1 ? 'administrator has' : 'administrators have') . ' it off',
            '/people',
            $noTwoStep === 0 ? '' : 'See who'
        );

        return $items;
    }

    /** @return array{key: string, title: string, done: bool, state: string, href: string, action: string} */
    private function item(string $key, string $title, bool $done, string $state, string $href, string $action): array
    {
        return ['key' => $key, 'title' => $title, 'done' => $done, 'state' => $state, 'href' => $href, 'action' => $action];
    }
}
