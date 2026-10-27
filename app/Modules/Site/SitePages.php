<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * The school's public website pages (ADR 0019): About, Admissions, Contact and
 * its policies. The school writes them; the platform never publishes words
 * the school has not approved. Starter drafts mark every fact only the
 * school can supply with [[double brackets]], and a page cannot be published
 * while any are left, so no invented address, fee, accreditation or policy
 * promise can reach the public site.
 */
final class SitePages
{
    /** slug => [default title, schema.org page type, one line for the admin list] */
    public const PAGES = [
        'about' => ['About us', 'AboutPage', 'Who you are, what you teach and where.'],
        'admissions' => ['Admissions', 'WebPage', 'Who can join, how to apply, what it costs and when.'],
        'contact' => ['Contact us', 'ContactPage', 'Your email, phone and website from the school settings, with any directions you add.'],
        'privacy' => ['Privacy policy', 'WebPage', 'What personal data the school keeps, why, and people\'s rights.'],
        'terms' => ['Terms of use', 'WebPage', 'The rules for using the school\'s website and learning platform.'],
        'refunds' => ['Refund policy', 'WebPage', 'When and how fees paid online are refunded.'],
        'safeguarding' => ['Safeguarding and child protection', 'WebPage', 'How the school keeps children safe and how to report a concern.'],
        'faq' => ['Questions and answers', 'FAQPage', 'The questions learners and parents ask most, answered.'],
        'careers' => ['Careers and teaching', 'WebPage', 'Teaching and working with the school, and how to apply.'],
    ];

    public const SOCIAL = [
        'facebook' => ['Facebook', ['facebook.com', 'www.facebook.com', 'm.facebook.com']],
        'instagram' => ['Instagram', ['instagram.com', 'www.instagram.com']],
        'x' => ['X (Twitter)', ['x.com', 'twitter.com', 'www.x.com', 'www.twitter.com']],
        'linkedin' => ['LinkedIn', ['linkedin.com', 'www.linkedin.com']],
        'youtube' => ['YouTube', ['youtube.com', 'www.youtube.com']],
        'tiktok' => ['TikTok', ['tiktok.com', 'www.tiktok.com']],
    ];

    private const PLACEHOLDER = '/\[\[[^\]]*\]\]/u';
    private const MIN_BODY = 120;

    public function __construct(
        private readonly SitePageRepository $pages,
        private readonly Connection $db,
        private readonly AuditLog $audit,
    ) {
    }

    public static function exists(string $slug): bool
    {
        return isset(self::PAGES[$slug]);
    }

    /** The saved page, or the starter draft if the school has not written it yet. */
    public function forEditing(Tenant $tenant, string $slug): SitePage
    {
        return $this->pages->find($tenant->id, $slug) ?? new SitePage(0, $tenant->id, $slug, self::PAGES[$slug][0], '', self::starter($slug, $tenant), SitePage::DRAFT, null, '');
    }

    /**
     * @param array{title: string, summary: string, body: string} $input
     */
    public function save(Tenant $tenant, CurrentUser $admin, string $slug, array $input, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $title = trim((string) preg_replace('/\s+/u', ' ', $input['title']));
        $summary = trim((string) preg_replace('/\s+/u', ' ', $input['summary']));
        $body = trim(str_replace("\r\n", "\n", $input['body']));
        $errors = [];
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            $errors['title'] = 'Give the page a title between 2 and 120 characters.';
        }
        if (mb_strlen($summary) > 300) {
            $errors['summary'] = 'Keep the summary under 300 characters; search results show about 155.';
        }
        if (mb_strlen($body) > 60000) {
            $errors['body'] = 'Keep the page under 60,000 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->transaction(function () use ($tenant, $admin, $slug, $title, $summary, $body, $clientIp): void {
            $this->pages->save($tenant->id, $slug, $title, $summary, $body, $admin->user->id);
            $this->audit->record($tenant->id, $admin->user->id, 'site.page_saved', 'site_page', null, ['page' => $slug], $clientIp);
        });
    }

    /** @return list<string> what stops this page going live, in plain words; empty when it can */
    public static function problems(SitePage $page, Tenant $tenant): array
    {
        $out = [];
        $left = preg_match_all(self::PLACEHOLDER, $page->title . "\n" . $page->summary . "\n" . $page->body);
        if ($left > 0) {
            $out[] = sprintf('Replace or delete the %d part%s in [[double brackets]] with your school\'s own details.', $left, $left === 1 ? '' : 's');
        }
        if (mb_strlen(trim($page->summary)) < 50) {
            $out[] = 'Write a summary of at least 50 characters. Search engines show it under the page title.';
        }
        if ($page->slug === 'contact') {
            if ($tenant->contactEmail === '' && $tenant->contactPhone === '') {
                $out[] = 'Add the school\'s email or phone number in Settings first: the contact page shows them.';
            }
        } elseif (mb_strlen(trim($page->body)) < self::MIN_BODY) {
            $out[] = 'Write at least a short paragraph (' . self::MIN_BODY . ' characters) so the page is useful.';
        }

        return $out;
    }

    public function publish(Tenant $tenant, CurrentUser $admin, string $slug, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $page = $this->pages->find($tenant->id, $slug);
        if ($page === null) {
            throw new ValidationException(['page' => 'Save the page before publishing it.']);
        }
        $problems = self::problems($page, $tenant);
        if ($problems !== []) {
            throw new ValidationException(['page' => implode(' ', $problems)]);
        }
        $this->pages->setStatus($tenant->id, $slug, SitePage::PUBLISHED, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'site.page_published', 'site_page', $page->id, ['page' => $slug], $clientIp);
    }

    public function unpublish(Tenant $tenant, CurrentUser $admin, string $slug, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $page = $this->pages->find($tenant->id, $slug);
        if ($page !== null && $page->isPublished()) {
            $this->pages->setStatus($tenant->id, $slug, SitePage::DRAFT, $admin->user->id);
            $this->audit->record($tenant->id, $admin->user->id, 'site.page_unpublished', 'site_page', $page->id, ['page' => $slug], $clientIp);
        }
    }

    /**
     * Official profiles and search console codes.
     *
     * @param array<string, string> $social network => URL
     * @param array{google?: string, bing?: string} $codes
     */
    public function saveSettings(Tenant $tenant, CurrentUser $admin, array $social, array $codes, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $errors = [];
        $links = [];
        foreach (self::SOCIAL as $key => [$label, $hosts]) {
            $url = trim($social[$key] ?? '');
            if ($url === '') {
                continue;
            }
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            if (
                !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array($host, $hosts, true)
                || trim($path, '/') === '' || isset($parts['user']) || mb_strlen($url) > 200 || preg_match('/[\s"<>]/', $url) === 1
            ) {
                $errors['social_' . $key] = sprintf('Paste the full address of your %s page, starting with https://%s/', $label, $hosts[0]);
                continue;
            }
            $links[$key] = 'https://' . $host . rtrim($path, '/');
        }
        $verification = [];
        foreach (['google' => '/^[A-Za-z0-9_-]{20,100}$/', 'bing' => '/^[A-Fa-f0-9]{32}$/'] as $engine => $pattern) {
            $code = self::verificationCode($codes[$engine] ?? '');
            if ($code === '') {
                continue;
            }
            if (preg_match($pattern, $code) !== 1) {
                $errors['verify_' . $engine] = $engine === 'google'
                    ? 'Paste the code Google Search Console gives you for the "HTML tag" method (the part inside content="…").'
                    : 'Paste the 32-character code Bing Webmaster Tools gives you for the "meta tag" method.';
                continue;
            }
            $verification[$engine] = $code;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->execute(
            'UPDATE tenants SET social_links = ?, search_verification = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$links === [] ? null : json_encode($links, JSON_UNESCAPED_SLASHES), $verification === [] ? null : json_encode($verification), $tenant->id],
        );
        $summary = ['profiles' => implode(',', array_keys($links)), 'verification' => implode(',', array_keys($verification))];
        $this->audit->record($tenant->id, $admin->user->id, 'site.settings_saved', 'tenant', $tenant->id, $summary, $clientIp);
    }

    /** Accepts the bare code or the whole meta tag a search console shows. */
    public static function verificationCode(string $input): string
    {
        $input = trim($input);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $input, $m) === 1) {
            return trim($m[1]);
        }

        return $input;
    }

    /**
     * Starter text. Everything the platform knows to be true is written out;
     * everything only the school knows is a [[placeholder]].
     */
    // phpcs:disable Generic.Files.LineLength -- Markdown paragraphs are single lines; a line break would show on the page.
    public static function starter(string $slug, Tenant $tenant): string
    {
        $name = $tenant->name;

        return match ($slug) {
            'about' => <<<MD
            [[One or two sentences: who {$name} is and who it teaches, for example "a technology school in Lagos for teenagers and adults".]]

            ## What we teach

            [[The programmes or subjects you offer, and who each one is for. Only list what you actually run.]]

            ## How learning works

            Lessons, quizzes and hands-on virtual labs are on this website, so learners can study on a phone or computer and pick up where they stopped. [[Say whether you also teach in person, and where.]]

            ## Our team

            [[Optional: the people who lead the school, with their real roles. Leave this section out if you prefer.]]
            MD,
            'admissions' => <<<MD
            [[Who can join: ages, levels or previous experience needed.]]

            ## How to apply

            [[The steps: for example, create an account, choose a course, pay or contact the school office.]]

            ## Fees

            Course prices are shown on each course page. [[Any other fees, discounts or payment plans, or delete this sentence.]]

            ## Dates

            [[When courses or terms start, or say that learners can start at any time.]]
            MD,
            'contact' => <<<MD
            We usually reply within [[how long, for example two working days]].

            If you already study with us, the quickest way to get help with a course or your account is **Get help** after you sign in.
            MD,
            'privacy' => <<<MD
            This policy explains what personal information {$name} keeps when you use this website and learning platform, and why.

            **Who is responsible:** [[The legal name of the organisation that runs the school, its address, and who to contact about privacy.]]

            ## What we keep

            - **Your account:** your name, email address, role (for example learner, teacher or parent) and a protected copy of your password. If you turn on two-step sign-in, a secret for your authenticator app.
            - **Your learning:** courses you join, lessons you open and finish, quiz answers and scores, lab results, work you hand in with any files, certificates, and the classes you are in.
            - **Your own notes and saved lessons:** these are private to you; teachers and parents cannot see them.
            - **Messages:** help requests you send and the replies.
            - **Payments:** for paid courses, the amount, date, course and payment reference. Card and bank details are entered on Paystack's own page and never reach us.
            - **Parents and guardians:** if the school links a parent or guardian to a learner, the link and what the school allowed them to see.
            - **Security records:** sign-ins and important changes. Network addresses in these records are stored in a scrambled (hashed) form. The web server also keeps standard access logs for [[how long your server keeps access logs]].

            ## Cookies

            We use one cookie to keep you signed in. We do not use advertising cookies or tracking from other companies.

            ## Why we use it

            To provide your courses, show progress to you and your teachers, issue certificates, take payments, answer help requests and keep accounts secure. [[Your lawful basis for each, for example contract or legitimate interest, as advised for the Nigeria Data Protection Act 2023 or other laws that apply to you.]]

            ## Who can see it

            Teachers and administrators at {$name} see what they need to teach and run the school. Parents and guardians see only what the school allows for their own child. [[Any other organisations you share data with, or say that you do not.]]

            ## How long we keep it

            [[How long you keep accounts and learning records after someone leaves.]]

            ## Your rights

            You can ask to see, correct or delete your information, or object to how it is used. Contact [[the email address for privacy requests]].

            *Last updated: [[date]].*
            MD,
            'terms' => <<<MD
            These terms apply to everyone who uses the {$name} website and learning platform.

            ## Your account

            Keep your password private and do not share your account. Tell us at once if you think someone else has used it.

            ## Using the platform

            Do your own work in quizzes, labs and assignments unless your teacher says otherwise. Do not upload anything unlawful, harmful or that belongs to someone else without permission.

            ## Course content

            [[Who owns the course materials, and what learners may and may not do with them.]]

            ## Fees

            [[Point to your Refund policy and admissions page, and state anything else about fees.]]

            ## Changes and contact

            [[How you will tell people about changes to these terms, and who to contact.]]

            *Last updated: [[date]].*
            MD,
            'refunds' => <<<MD
            Online payments for courses are taken by Paystack. A course opens as soon as Paystack confirms the payment.

            ## When we refund

            [[The situations in which you give a refund, and any time limits, for example within 7 days if no more than one lesson has been opened.]]

            ## How to ask

            [[How to ask for a refund, and what information to include, for example the payment reference shown under Your account.]]

            ## How long it takes

            [[How long refunds take and how the money is returned.]]

            *Last updated: [[date]].*
            MD,
            'safeguarding' => <<<MD
            {$name} is committed to keeping children and young people safe while they learn with us, online and in person.

            ## Who to contact

            Our designated safeguarding lead is [[name and role]], reachable at [[email or phone]]. If a child is in immediate danger, call the emergency services first.

            ## How the platform protects learners

            - Parents and guardians are linked to a learner only after the school has checked who they are, and see only what the school allows.
            - Learners cannot message each other privately on the platform.
            - Learners' own notes are private to them.
            - Help requests go only to the school's administrators.

            ## Reporting a concern

            [[How staff, parents and learners report a concern, and what happens next.]]

            ## Our policy

            [[Link to or summarise your full safeguarding policy, and when it is reviewed.]]

            *Last updated: [[date]].*
            MD,
            'faq' => <<<MD
            ## How do I join?

            [[How people get an account: they sign up on this website, use a joining code from the school, or the school adds them.]]

            ## Can I learn on my phone?

            Yes. Every page works on a phone, and the course remembers where you stopped. After you sign in, you can save lessons to read later without data.

            ## What does it cost?

            Each course page shows its price, or that it is free. [[Anything else about fees, or delete this sentence.]]

            ## Do I get a certificate?

            [[Say whether you give certificates and what a learner must finish to earn one.]]

            ## I forgot my password

            Choose **Forgotten your password?** on the sign-in page and we will email you a link to choose a new one.

            ## How do I get help?

            Sign in and choose **Get help**. [[How long you usually take to reply.]]
            MD,
            'careers' => <<<MD
            [[One or two sentences on why people teach or work at {$name}.]]

            ## Open roles

            [[The roles you are hiring for now, with what each involves and how to apply; or say that there are no vacancies at present.]]

            ## Teaching on this platform

            Teachers write courses with lessons, quizzes and hands-on labs, review the work learners hand in, answer their questions and can run live classes online.

            ## How to apply

            If you already have an account here, you can ask to teach from [the teaching application page](/join/teach). [[Otherwise, who to write to and what to send.]]
            MD,
            default => '',
        };
    }
    // phpcs:enable
}
