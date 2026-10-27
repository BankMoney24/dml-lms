<?php

declare(strict_types=1);

namespace App\Modules\Blog;

use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Starter drafts for a new blog (ADR 0034). They say only what the platform and the
 * school's own settings make true; the few choices they make are listed in choices().
 * They are only ever saved as drafts: a person reads and publishes each one.
 */
final class StarterPosts
{
    public function __construct(private readonly CourseRepository $courses)
    {
    }

    /** @return list<string> */
    public static function choices(): array
    {
        return [
            '“How to spot a payment scam” says the school never asks for a card PIN, a one-time password (OTP) or a banking password, and that fees are paid only on the course’s payment page.',
        ];
    }

    /**
     * @return list<array{slug: string, title: string, summary: string, category: string, body: string}>
     */
    public function posts(Tenant $tenant): array
    {
        $name = $tenant->name;
        $help = $tenant->contactEmail !== '' ? 'sign in and choose **Get help**, or write to ' . $tenant->contactEmail : 'sign in and choose **Get help**';
        $certificate = $tenant->certificatesEnabled ? "\n- **A certificate** when you complete a course, with a code anyone can check on the [certificate check page](/verify)." : '';
        $everyday = $this->courses->browse($tenant->id, ['track' => 'everyday'], 12);

        // phpcs:disable Generic.Files.LineLength -- Markdown paragraphs are single lines; a line break would show on the page.
        $posts = [
            [
                'slug' => 'welcome-to-our-learning-website',
                'title' => 'Welcome to our learning website',
                'summary' => 'What you can do on the ' . $name . ' learning website: courses, quizzes and labs on your phone or computer, lessons you can save for offline, and help when you need it.',
                'category' => 'news',
                'body' => <<<MD
                This is where {$name} teaches online. Here is what you will find.

                - **Courses** made of short lessons, quizzes that explain each answer, and hands-on labs. Each course remembers where you stopped, on any device.
                - **Learning without data:** after you sign in, save a lesson or a whole course and read it later without a connection.{$certificate}
                - **Help from real people:** {$help}.

                See every course on the [courses page](/courses), or how they are organised on [Programmes](/programmes).

                We will post news, events, learners' stories and short guides here. You can follow them with any feed reader using the [blog feed](/blog/feed.xml).
                MD,
            ],
            [
                'slug' => 'learning-on-your-phone-with-little-data',
                'title' => 'Learning on your phone with little data',
                'summary' => 'Five ways to keep learning when data is expensive or the network is weak: save lessons for offline, read rather than stream, and let finished lessons send themselves later.',
                'category' => 'guides',
                'body' => <<<'MD'
                Data costs money and the network is not always there. You can still keep learning.

                ## 1. Save lessons while you are on Wi-Fi

                Sign in, open a course and choose **Save the whole course for offline**, or **Save for offline** on a single lesson. Saved lessons open on that phone even with no connection.

                ## 2. Read first, watch later

                Reading lessons use very little data. Leave videos for when you are on Wi-Fi, or download them once from the lesson.

                ## 3. Finish lessons offline

                If you press **Mark as complete** with no connection, your phone keeps it and sends it when you are back online. A message tells you when it has been sent.

                ## 4. Keep your place

                Each course remembers where you stopped, so a short session on the bus is never wasted.

                ## 5. Sign out on shared phones

                Signing out removes your saved lessons from the phone, so the next person does not see your work.
                MD,
            ],
            [
                'slug' => 'how-to-spot-a-payment-scam',
                'title' => 'How to spot a payment scam',
                'summary' => 'How to pay for a course safely: where to pay, what we will never ask you for, and what to do if a message about fees looks wrong.',
                'category' => 'guides',
                'body' => <<<MD
                People sometimes pretend to be a school to collect fees or card details. Here is how to stay safe.

                ## Where to pay

                Pay only on the **payment page of the course** on this website, reached by choosing **Subscribe** on the course. Check that the address in your browser is this website before you type anything.

                ## What we will never ask for

                - Your card PIN.
                - A one-time password (OTP) sent to your phone.
                - Your online banking password.
                - Payment into a personal account by message, call or social media.

                ## If something looks wrong

                Do not pay and do not reply. Check with us first: {$help}. You can see every payment you made under **My subscriptions** after you sign in.
                MD,
            ],
        ];
        if ($everyday !== []) {
            $list = implode("\n", array_map(static fn (Course $c): string => '- [' . $c->title . '](/courses/' . $c->slug . ')' . ($c->summary !== '' ? ': ' . mb_strimwidth($c->summary, 0, 140, '…') : ''), $everyday));
            $posts[] = [
                'slug' => 'learning-for-everyday-life',
                'title' => 'Learning for everyday life',
                'summary' => 'Short, practical courses for life and work outside school: your phone, money online, selling and staying safe. Learn at your own pace, no timetable needed.',
                'category' => 'news',
                'body' => <<<MD
                Not everyone who wants to learn is in a classroom. Our everyday learning courses are for anyone who wants practical skills for life and work, at their own pace.

                {$list}

                Lessons are short, quizzes explain every answer, and you can save lessons to read without data. See them all on [Programmes](/programmes#everyday).
                MD,
            ];
        }
        // phpcs:enable

        return $posts;
    }
}
