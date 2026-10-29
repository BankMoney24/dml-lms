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
        private readonly ?\App\Core\Database\Connection $db = null,
    ) {
    }

    /** Pages that have a full draft. */
    public const PAGES = ['about', 'admissions', 'contact', 'faq', 'careers', 'privacy', 'terms', 'refunds', 'safeguarding'];

    /**
     * The policy choices each draft makes, to read before publishing.
     *
     * @return list<string>
     */
    public static function choices(string $slug): array
    {
        return match ($slug) {
            'about' => [
                'Describes the school only from its settings (name, kind, tagline, levels, contact details) and its published courses.',
                'Says nothing about when the school was founded, where it is, its staff, partners, results or accreditation. Add those yourself if you want them on the page.',
            ],
            'contact' => ['Replies within two working days.'],
            'faq' => ['Help requests are answered within two working days, as on the contact page.'],
            'careers' => [
                'Says there are no advertised vacancies at present and that applications are kept for when places open. Edit this if you are hiring now.',
                'Asks for practical experience in the subject, and a background check before anyone works with learners under 18.',
            ],
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
            'about' => [
                'title' => 'About us',
                'summary' => 'Who ' . $name . ' is, what it teaches and how learning works here: courses, practical work, teachers and certificates.',
                'body' => $this->about($tenant),
            ],
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
            'faq' => [
                'title' => 'Questions and answers',
                'summary' => 'Answers to the questions people ask most about learning at ' . $name . ': joining, costs, phones and data, certificates and help.',
                'body' => $this->faq($tenant, $online),
            ],
            'careers' => [
                'title' => 'Careers and teaching',
                'summary' => 'Teaching and working with ' . $name . ', what teachers do on the platform, and how to apply.',
                'body' => <<<MD
                {$name} is built around teachers who know their subject from practice and can explain it plainly. If that is you, we would like to hear from you.

                ## Open roles

                There are no advertised vacancies at present. We keep every application and contact people when a place opens that suits them.

                ## What teachers do here

                - Write and improve courses: lessons, quizzes with explained answers, and hands-on labs.
                - Review the work learners hand in and give feedback they can act on.
                - Answer learners' questions under each lesson.
                - Run live classes and meetings online, and take the register.
                - Follow each learner's progress and see who needs help.

                ## Who we look for

                - Practical experience in the subject you want to teach.
                - Clear, patient explanations, for beginners as well as advanced learners.
                - A background check before working with learners under 18, as our [safeguarding policy](/safeguarding) requires.

                ## How to apply

                If you already have an account here, sign in and ask to teach from the [teaching application page](/join/teach); tell us what you would teach and your experience. Otherwise, write to us {$reach} with the same details.
                MD,
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

    // phpcs:disable Generic.Files.LineLength -- answers are single Markdown paragraphs.
    private function faq(Tenant $tenant, bool $online): string
    {
        $join = match (true) {
            $tenant->registration === 'open' => 'Choose **Join** at the top of any page and make a free account with your email address. Then choose a course.',
            $tenant->registration === 'code' && $tenant->joinCode !== null => 'Choose **Join** and type the joining code the school gave you. If you do not have one, ask the school ' . $this->reach($tenant) . '.',
            default => 'The school makes accounts for its learners. Ask the school ' . $this->reach($tenant) . '.',
        };
        $paid = $this->courses->listPaid($tenant->id) !== [];
        $cost = !$paid
            ? 'Our courses are free at the moment. Each course page says so.'
            : 'Each course page shows its price, or that it is free. ' . ($online ? 'You can pay online by card, bank transfer or USSD through Paystack, or pay the school directly.' : 'You pay the school directly, using the details on the course\'s payment page.')
              . ' If you have a discount code, type it before you pay. See our [Refund policy](/refunds) for refunds.';
        $everyday = $this->courses->browse($tenant->id, ['track' => 'everyday'], 1) !== [];
        $qa = [
            'How do I join?' => $join,
            'What does it cost?' => $cost,
            'Can I learn on my phone?' => 'Yes. Every page works on a phone, and each course remembers where you stopped, so you can carry on from any device.',
            'Can I learn without data?' => 'After you sign in, choose **Save for offline** on a lesson, or save a whole course from its page. Saved lessons open without a connection on that phone or computer. Lessons you finish offline are sent when you reconnect. Signing out removes them from the device.',
        ];
        if ($everyday) {
            $qa['I am not in school. Is there something for me?'] = 'Yes. Our [everyday learning courses](/programmes#everyday) are short and practical: using your phone, money online, selling and staying safe. Learn at your own pace; no teacher or timetable needed.';
        }
        if ($tenant->certificatesEnabled) {
            $qa['Do I get a certificate?'] = 'Yes, when you complete a course. Each certificate has a code that anyone can check on our [certificate check page](/verify).';
        }
        if ($tenant->parentRequests) {
            $qa['Can parents follow their child\'s progress?'] = 'Yes. A parent or guardian can ask from the [parent page](/join/parent). The school checks who you are before linking you, and you see what the school allows, including a report sheet you can print.';
        }
        $qa['I forgot my password'] = 'Choose **Forgotten your password?** on the sign-in page and we will email you a link to choose a new one.';
        $qa['How do I get help?'] = 'Sign in and choose **Get help**. Your message goes to the school\'s administrators and we usually reply within two working days. You can also reach us ' . $this->reach($tenant) . '.';

        $out = [];
        foreach ($qa as $q => $a) {
            $out[] = '## ' . $q . "\n\n" . $a;
        }

        return implode("\n\n", $out);
    }
    // phpcs:enable

    /** About us, from the school's settings and published courses only: no invented history, people or claims. */
    private function about(Tenant $tenant): string
    {
        $name = $tenant->name;
        $kind = match ($tenant->kind) {
            'university' => 'a university',
            'polytechnic' => 'a polytechnic',
            'training_centre' => 'a training centre',
            'community' => 'a community learning programme',
            default => 'a school',
        };
        $courses = $this->courses->listPublished($tenant->id, 100);
        $byTrack = [];
        foreach ($courses as $c) {
            $byTrack[$c->track ?? 'other'][] = $c;
        }
        $trackNames = ['school' => 'School subjects', 'skills' => 'Practical and professional skills', 'everyday' => 'Everyday learning', 'other' => 'Other courses'];
        $taught = [];
        foreach ($trackNames as $key => $label) {
            if (!isset($byTrack[$key])) {
                continue;
            }
            $lines = array_map(static function (Course $c): string {
                $bits = array_filter([$c->levelLabel(), $c->durationHours !== null ? 'about ' . $c->durationHours . ' hours' : null]);
                $summary = trim($c->summary);
                if (mb_strlen($summary) > 140) {
                    // Cut at a word, so the sentence ends cleanly with an ellipsis.
                    $cut = mb_substr($summary, 0, 140);
                    $summary = rtrim(mb_substr($cut, 0, max(1, (int) mb_strrpos($cut, ' '))), " ,;:.") . '…';
                } elseif ($summary !== '') {
                    $summary = rtrim($summary, '.') . '.';
                }
                $summary = $summary === '' ? '' : ': ' . $summary;

                return sprintf('- [%s](/courses/%s)%s%s', $c->title, $c->slug, $bits === [] ? '' : ' (' . strtolower(implode(', ', $bits)) . ')', $summary);
            }, array_slice($byTrack[$key], 0, 12));
            $taught[] = '### ' . $label . "\n\n" . implode("\n", $lines);
        }
        $count = count($courses);
        $intro = $tenant->tagline !== '' ? $name . ': ' . rtrim($tenant->tagline, '.') . '.' . "\n\n" : '';
        $intro .= $name . ' is ' . $kind . ' that teaches on this website, so learners can study on a phone or a computer, at their own pace, and pick up where they stopped.';
        if ($count > 0) {
            $intro .= ' We run ' . $count . ' ' . ($count === 1 ? 'course' : 'courses') . ' at the moment.';
        }
        if ($tenant->levels !== []) {
            $intro .= ' We teach at these levels: ' . implode(', ', $tenant->levels) . '.';
        }
        $labs = $this->db === null ? 0 : (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM lessons l JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
             WHERE l.tenant_id = ? AND c.status = 'published' AND l.type = 'lab'",
            [$tenant->id],
        );
        $how = [
            '- **Lessons** to read and watch, which you can also save to study offline.',
            '- **Quizzes** that explain every answer, so a wrong answer still teaches you something.',
        ];
        if ($labs > 0) {
            $how[] = '- **Hands-on labs** in the browser: build circuits, networks or code and see the result, with new numbers every time.';
        }
        $how[] = '- **Teachers** who answer questions under every lesson and review the work learners hand in.';
        if ($tenant->certificatesEnabled) {
            $how[] = '- **Certificates** for finished courses, which anyone can check online with the certificate number.';
        }
        if ($tenant->parentRequests) {
            $how[] = '- **Parents and guardians** can follow their child\'s progress once the school has checked who they are.';
        }
        $what = $taught === []
            ? 'Our courses will be listed here soon. See [all courses](/courses).'
            : implode("\n\n", $taught) . "\n\nSee [all courses](/courses), or [programmes](/programmes) for how they fit together.";
        $reach = $this->reach($tenant);

        return $intro . "\n\n## What we teach\n\n" . $what . "\n\n## How learning works here\n\n" . implode("\n", $how)
            . "\n\n## Join us\n\nSee [Admissions](/admissions) for how to start, or contact us " . $reach . '. You can also read our [questions and answers](/faq).';
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
