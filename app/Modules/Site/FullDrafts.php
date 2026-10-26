<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Payments\PaymentAccountRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Complete first drafts of the school's pages (ADR 0033), for schools that want
 * a page they can read, adjust and publish rather than a starter with gaps.
 *
 * Facts come from the school's own settings and courses (name, email, phone,
 * levels, published courses and prices, whether online payment is on, what the
 * platform really does). Where a policy needs a choice the platform cannot know,
 * the draft makes a common, cautious one and lists it in choices(), so the
 * person publishing knows exactly what they are agreeing to. Nothing here is
 * published by itself: a draft is saved, read, and then published by a person.
 */
final class FullDrafts
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly ?PaymentAccountRepository $payments = null,
    ) {
    }

    /** Pages that have a full draft. */
    public const PAGES = ['admissions', 'contact', 'privacy', 'terms', 'refunds', 'safeguarding'];

    /**
     * The policy choices each draft makes, to read before publishing.
     *
     * @return list<string>
     */
    public static function choices(string $slug): array
    {
        return match ($slug) {
            'contact' => ['Replies within two working days.'],
            'admissions' => ['Self-paced courses can be started at any time; dates for classes are announced to the people in them.'],
            'privacy' => [
                'Lawful bases: contract, legitimate interests, legal obligation and consent, as the Nigeria Data Protection Act 2023 sets out.',
                'Requests about personal data are answered within one month; live classes are not recorded unless the teacher tells the class first.',
                'Accounts are kept while active and for two years after the last sign-in, then deleted or anonymised; certificates and payment records are kept longer (see the page).',
                'Server access logs are kept for about five weeks.',
            ],
            'terms' => ['Course materials belong to the school or their authors and are for personal learning only.', 'Signed-in users are told about important changes to the terms.'],
            'refunds' => [
                'A full refund within 7 days of paying, if no more than one lesson of the course has been finished.',
                'A full refund when the school cancels a course, and the duplicate amount when someone pays twice.',
                'Discounted places are refunded at the amount actually paid.',
            ],
            'safeguarding' => [
                'The school administrator is the safeguarding lead until the school names someone else.',
                'Concerns are recorded the same day and the policy is reviewed every year.',
            ],
            default => [],
        };
    }

    /** @return array{title: string, summary: string, body: string}|null */
    public function draft(string $slug, Tenant $tenant, string $today): ?array
    {
        $name = $tenant->name;
        $email = $tenant->contactEmail;
        $phone = $tenant->contactPhone;
        $reach = $this->reach($tenant);
        $date = date('j F Y', (int) strtotime($today . ' 00:00:00 UTC'));
        $online = $this->payments?->find($tenant->id) !== null;

        // phpcs:disable Generic.Files.LineLength -- Markdown paragraphs are single lines; a line break would show on the page.
        return match ($slug) {
            'contact' => [
                'title' => 'Contact us',
                'summary' => 'How to reach ' . $name . ' about courses, admissions, payments or your account.',
                'body' => <<<MD
                We reply as soon as we can, usually within two working days.

                ## Already studying with us?

                Sign in and choose **Get help**. Your message goes straight to the school's administrators with the details of your account, and you can follow the reply on the same page.

                ## About admissions or fees

                See [Admissions](/admissions) for who can join and how. Each course page shows its price and what it includes.

                MD . ($tenant->parentRequests ? "\n\n## Parents and guardians\n\nIf your child learns with us, you can ask to follow their progress from the [parent page](/join/parent). The school checks who you are before linking you." : ''),
            ],
            'admissions' => [
                'title' => 'Admissions',
                'summary' => 'Who can join ' . $name . ', how to start a course, what it costs and how to pay.',
                'body' => $this->admissions($tenant, $online),
            ],
            'privacy' => [
                'title' => 'Privacy policy',
                'summary' => 'What personal information ' . $name . ' keeps when you use this website and learning platform, why, for how long, and your rights.',
                'body' => <<<MD
                This policy explains what personal information {$name} ("we") keeps when you use this website and learning platform, why we keep it, and what you can ask us to do with it. It is written to meet the Nigeria Data Protection Act 2023.

                **Who is responsible:** {$name}. Contact us about privacy {$reach}.

                ## What we keep

                - **Your account:** your name, email address, your role (for example learner, teacher or parent) and a protected copy of your password. If you turn on two-step sign-in, an encrypted secret for your authenticator app.
                - **Your learning:** the courses you join, lessons you open and finish, quiz answers and scores, lab results, work you hand in with any files, certificates, the classes you are in and attendance at live classes.
                - **Your own notes and saved lessons:** private to you; teachers and parents cannot see them.
                - **Messages:** help requests you send us and our replies; questions and answers you post under lessons.
                - **Payments:** for paid courses, the amount, date, course, payment reference, any discount code and any refund. Card and bank details are typed on Paystack's own page and never reach us.
                - **Parents and guardians:** if we link a parent or guardian to a learner, the link, who checked it, and what we allowed them to see.
                - **Live classes and meetings:** when you joined and left, to count attendance. Classes are not recorded unless the teacher tells you first.
                - **Security records:** sign-ins and important changes, with network addresses stored in a scrambled (hashed) form. The web server also keeps standard access logs for about five weeks.

                ## Cookies

                We use one cookie, to keep you signed in. We do not use advertising cookies or tracking from other companies.

                ## Why we use it, and on what basis

                - To give you your courses, record your progress, mark your work and issue certificates: **contract** (the service you joined for).
                - To keep accounts and the platform secure and to improve our teaching: **legitimate interests**.
                - To keep payment records: **legal obligation**.
                - To link parents and guardians, and for anything optional we ask about: **consent**, which you can withdraw at any time.

                ## Who can see it

                Teachers and administrators at {$name} see what they need to teach and run the school. Parents and guardians see only what we allow for their own child. We use these services to run the platform, and they handle your information only on our instructions: our hosting provider, Paystack (payments), our email provider (account emails) and, for live classes, our own video meeting server. We do not sell personal information.

                ## How long we keep it

                - Accounts and learning records: while your account is active, and for two years after your last sign-in; then we delete or anonymise them.
                - Certificates: as long as people may need to check them, so the certificate number stays valid.
                - Payment records: as long as tax and accounting law requires, usually six years.
                - Backups: encrypted, and deleted after two weeks.

                ## Your rights

                You can ask to see the information we hold about you, to correct it, to delete it, to have a copy, or to object to how we use it. Ask us {$reach}. We reply within one month. If you are not satisfied, you can complain to the Nigeria Data Protection Commission.

                ## Children

                Learners under 18 join with the agreement of a parent or guardian. Parents and guardians can make these requests for their child.

                *Last updated: {$date}.*
                MD,
            ],
            'terms' => [
                'title' => 'Terms of use',
                'summary' => 'The rules for using the ' . $name . ' website and learning platform: accounts, fair use, course materials, fees and changes.',
                'body' => <<<MD
                These terms apply to everyone who uses the {$name} website and learning platform. By creating an account or using it, you agree to them.

                ## Your account

                Keep your password private and do not share your account. Tell us at once {$reach} if you think someone else has used it. Learners under 18 need the agreement of a parent or guardian.

                ## Using the platform fairly

                - Do your own work in quizzes, labs and assignments unless your teacher says otherwise.
                - Be respectful in questions and answers under lessons.
                - Do not upload anything unlawful, harmful or that belongs to someone else without permission.
                - Do not try to get into accounts or parts of the platform that are not yours, or to disrupt it.

                We may suspend an account that breaks these rules.

                ## Course materials

                Lessons, videos, quizzes and other materials belong to {$name} or their authors. You may use them for your own learning, including saving them on your device to study offline. Do not copy, sell or share them publicly without permission.

                ## Certificates

                Certificates are issued when a course's requirements are met. Anyone can check a certificate number on this website. We may withdraw a certificate obtained dishonestly; the check then says so.

                ## Fees

                Course prices are shown on each course page. See our [Refund policy](/refunds) and [Admissions](/admissions).

                ## Changes and contact

                We may change these terms; the date below shows the latest version, and we tell signed-in users about important changes. Questions: contact us {$reach}.

                *Last updated: {$date}.*
                MD,
            ],
            'refunds' => [
                'title' => 'Refund policy',
                'summary' => 'When ' . $name . ' refunds course fees, how to ask, and how long the money takes to come back.',
                'body' => <<<MD
                Online payments for courses are taken by Paystack. A course opens as soon as the payment is confirmed.

                ## When we refund

                - **Changed your mind:** a full refund if you ask within 7 days of paying and have finished no more than one lesson of the course.
                - **We cancel a course:** a full refund, or a place on another course if you prefer.
                - **Paid twice by mistake:** we refund the extra payment.
                - **Discount codes:** we refund the amount you actually paid.

                After a full refund the course closes. Your progress is kept, so if you join again later you can continue where you stopped.

                ## How to ask

                Sign in, choose **Get help**, and tell us the course and the payment reference (shown on your receipt under **Your account**). You can also contact us {$reach}.

                ## How long it takes

                Refunds of online payments go back to the card or account you paid with. Paystack usually completes them within a few working days, and your bank may take a little longer to show the money. You get a notification on the platform when we start the refund. If you paid the school directly, we pay you back the same way.

                *Last updated: {$date}.*
                MD,
            ],
            'safeguarding' => [
                'title' => 'Safeguarding and child protection',
                'summary' => 'How ' . $name . ' keeps children and young people safe while they learn, online and in person, and how to report a concern.',
                'body' => <<<MD
                {$name} is committed to keeping children and young people safe while they learn with us, online and in person. Everyone who works with us shares that responsibility.

                ## Who to contact

                Our safeguarding lead is the school administrator, reachable {$reach}. If a child is in immediate danger, call the emergency services first, then tell us.

                ## How the platform protects learners

                - Parents and guardians are linked to a learner only after the school has checked who they are, and they see only what the school allows.
                - Learners cannot message each other privately on the platform.
                - Learners' own notes are private to them.
                - Help requests go only to the school's administrators.
                - Only people invited to a live class or meeting can join it; classes are not recorded unless the teacher tells the class first.

                ## Reporting a concern

                Anyone (a learner, parent, teacher or visitor) can report a worry about a child's safety. Tell the safeguarding lead the same day, by message, phone or in person. Say what you saw or heard, when, and who was involved; do not investigate yourself.

                We record every concern on the day we receive it, act to keep the child safe, and involve parents and the authorities when the law or the child's safety requires it. Information is shared only with people who need it.

                ## Our staff

                Staff and volunteers who work with children are told about this policy when they join and are expected to follow it.

                ## Review

                We review this policy every year, and after any serious incident.

                *Last updated: {$date}.*
                MD,
            ],
            default => null,
        };
        // phpcs:enable
    }

    /** "by email at …, or by phone on …" from the school's settings (both are optional). */
    private function reach(Tenant $tenant): string
    {
        $parts = [];
        if ($tenant->contactEmail !== '') {
            $parts[] = 'by email at ' . $tenant->contactEmail;
        }
        if ($tenant->contactPhone !== '') {
            $parts[] = 'by phone on ' . $tenant->contactPhone;
        }

        return $parts === [] ? 'through **Get help** after you sign in' : implode(', or ', $parts);
    }

    private function admissions(Tenant $tenant, bool $online): string
    {
        $courses = $this->courses->listPublished($tenant->id, 60);
        $lines = array_map(static function (Course $c): string {
            $bits = array_filter([$c->levelLabel(), $c->durationHours !== null ? 'about ' . $c->durationHours . ' hours' : null]);

            return sprintf('- [%s](/courses/%s): %s%s', $c->title, $c->slug, $c->priceLabel() ?? 'free', $bits === [] ? '' : ' (' . strtolower(implode(', ', $bits)) . ')');
        }, $courses);
        $levels = $tenant->levels === [] ? '' : ' We teach at these levels: ' . implode(', ', $tenant->levels) . '.';
        $group = $tenant->groupWord(true);
        $pay = $online
            ? 'Pay online by card, bank transfer or USSD through Paystack, or pay the school directly and tell us on the course\'s payment page. The course opens as soon as the payment is confirmed.'
            : 'Pay the school directly using the details on the course\'s payment page, then tell us there. The course opens as soon as we confirm the payment.';
        $list = $lines === [] ? 'Our courses will be listed here soon.' : implode("\n", $lines);
        $name = $tenant->name;

        // phpcs:disable Generic.Files.LineLength
        return <<<MD
        {$name} welcomes learners who want to build practical skills, whether they are in school, working, or learning for themselves.{$levels} Learners under 18 join with the agreement of a parent or guardian.

        ## How to start

        1. Create a free account (or sign in) and choose a course.
        2. On the course page, choose **Subscribe**. Free courses open straight away.
        3. For a paid course: {$pay}
        4. Some courses take applications: fill in the short form on the course page and we offer places in the order people applied.

        Learners in one of our {$group} are given their courses by the school, so they do not need to pay for those separately.

        ## Courses and fees

        {$list}

        Prices are for the whole course. If you have a discount code, type it on the payment page before you pay.

        ## Dates

        Self-paced courses can be started at any time, and the course remembers where you stopped. Dates for live classes are shown to the people in each class and on their calendar.

        ## Questions

        See [Contact us](/contact), or our [Refund policy](/refunds).
        MD;
        // phpcs:enable
    }
}
