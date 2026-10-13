<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Cybersecurity: an inbox of realistic emails; mark each one safe or
 * phishing. Which ones are phishing (and why) stays on the server until the
 * learner has answered. All names, addresses and links are invented
 * examples; nothing in the inbox is clickable.
 */
final class PhishingLab implements Lab
{
    private const PICK = 8;

    /** The email pool; which ones are phishing, and why, stays here. */
    private const POOL = [
        'bank-verify' => [
            'from' => 'GTBank Alerts',
            'address' => 'alerts@gtbank-secure-login.com',
            'subject' => 'Your account will be blocked in 24 hours',
            'body' => "Dear customer,\nWe noticed unusual activity. Verify your BVN and card PIN now or your account will be blocked.",
            'link_text' => 'Verify my account',
            'link_url' => 'http://gtbank-secure-login.com/verify',
            'attachment' => '',
            'phishing' => true,
            'why' => 'A real bank never asks for your PIN, and the address is not the bank’s own domain. The deadline is there to rush you.',
        ],
        'bank-real' => [
            'from' => 'GTBank',
            'address' => 'no-reply@gtbank.com',
            'subject' => 'Debit alert: NGN 5,000.00',
            'body' => "Acct: 01****789\nAmt: NGN 5,000.00 DR\nDesc: POS purchase\nWe will never ask for your PIN or password.",
            'link_text' => '',
            'link_url' => '',
            'attachment' => '',
            'phishing' => false,
            'why' => 'An ordinary alert from the bank’s own domain, with no link and no request for details.',
        ],
        'jamb-result' => [
            'from' => 'JAMB Results Portal',
            'address' => 'jamb.results.portal@gmail.com',
            'subject' => 'Upgrade your UTME score: limited slots',
            'body' => 'Pay N10,000 to our officer to upgrade your UTME score before results are final. Send your registration number and payment today.',
            'link_text' => 'Pay and upgrade',
            'link_url' => 'http://jamb-upgrade-score.online/pay',
            'attachment' => '',
            'phishing' => true,
            'why' => 'Scores cannot be bought. An official body does not use a Gmail address, and the link points to an unrelated site.',
        ],
        'school-fees' => [
            'from' => 'Bursar, Your School',
            'address' => 'bursary@yourschool.edu.ng',
            'subject' => 'Second term fees schedule',
            'body' => "Good morning,\nThe second term fees schedule is attached. Pay only at the school bank account listed in the document or at the bursary.",
            'link_text' => '',
            'link_url' => '',
            'attachment' => 'fees-schedule-2nd-term.pdf',
            'phishing' => false,
            'why' => 'From the school’s own domain, expected at this time of term, and it tells you to pay only through official channels.',
        ],
        'parcel-fee' => [
            'from' => 'DHL Express',
            'address' => 'support@dhl-delivery-fee.net',
            'subject' => 'Your parcel is on hold: customs fee unpaid',
            'body' => 'We could not deliver your parcel. Pay a N1,500 customs fee within 12 hours to avoid return to sender.',
            'link_text' => 'Pay customs fee',
            'link_url' => 'http://dhl-delivery-fee.net/pay',
            'attachment' => '',
            'phishing' => true,
            'why' => 'You were not expecting a parcel, the domain is not dhl.com, and it asks for a small payment urgently.',
        ],
        'password-expiry' => [
            'from' => 'Microsoft 365 Team',
            'address' => 'admin@m1crosoft-support.com',
            'subject' => 'Your password expires today',
            'body' => 'Your mailbox password expires today. Keep your current password by signing in below.',
            'link_text' => 'Keep my password',
            'link_url' => 'http://m1crosoft-support.com/login',
            'attachment' => '',
            'phishing' => true,
            'why' => '“m1crosoft” uses the number 1 instead of the letter i. Real password changes happen in your account settings, not from an email link.',
        ],
        'shared-doc' => [
            'from' => 'Mrs Adaeze Okoro',
            'address' => 'adaeze.okoro@yourschool.edu.ng',
            'subject' => 'Physics practical worksheet for Thursday',
            'body' => "Hello class,\nHere is the worksheet for Thursday’s practical. Please print it or bring it on your phone.",
            'link_text' => 'Physics practical worksheet',
            'link_url' => 'https://docs.google.com/document/d/1a2b3c',
            'attachment' => '',
            'phishing' => false,
            'why' => 'From a teacher at your school’s domain, about something expected, and the link goes to Google Docs.',
        ],
        'data-promo' => [
            'from' => 'MTN Promo',
            'address' => 'promo@mtn-free-data.xyz',
            'subject' => 'Congratulations! You won 100GB free data',
            'body' => 'You have been selected for 100GB free data. Enter your phone number and the code we send you to claim.',
            'link_text' => 'Claim 100GB now',
            'link_url' => 'http://mtn-free-data.xyz/claim',
            'attachment' => '',
            'phishing' => true,
            'why' => 'Too good to be true, an odd domain (.xyz), and asking for the code sent to your phone lets them take over your account.',
        ],
        'principal-giftcard' => [
            'from' => 'Principal',
            'address' => 'principal.yourschool@gmail.com',
            'subject' => 'Urgent and confidential',
            'body' => 'Are you available? I need you to buy four iTunes gift cards for a visitor and send me the codes. I will refund you today. Don’t tell anyone yet.',
            'link_text' => '',
            'link_url' => '',
            'attachment' => '',
            'phishing' => true,
            'why' => 'A classic impersonation: a personal Gmail address, secrecy, urgency and gift cards, which cannot be traced or refunded.',
        ],
        'course-reminder' => [
            'from' => 'DML School of Technology',
            'address' => 'no-reply@dmlschooloftechnology.com',
            'subject' => 'Reminder: your Networking quiz is due Friday',
            'body' => "Hi,\nThis is a reminder that the Module 2 quiz in Networking Basics closes on Friday. Sign in to your school site to take it.",
            'link_text' => '',
            'link_url' => '',
            'attachment' => '',
            'phishing' => false,
            'why' => 'A routine reminder that asks you to sign in the usual way, not through a link in the email.',
        ],
        'invoice-zip' => [
            'from' => 'Accounts Department',
            'address' => 'accounts@invoice-docs.biz',
            'subject' => 'Overdue invoice #88213',
            'body' => 'Please find the overdue invoice attached. Open it immediately to avoid legal action.',
            'link_text' => '',
            'link_url' => '',
            'attachment' => 'Invoice_88213.zip',
            'phishing' => true,
            'why' => 'An unexpected invoice with a zip attachment and a threat. Zip files from strangers often hide malware.',
        ],
        'shop-order' => [
            'from' => 'Jumia',
            'address' => 'no-reply@jumia.com.ng',
            'subject' => 'Your order has shipped',
            'body' => 'Your order JMA-2210 (USB flash drive, 32 GB) has shipped and should arrive in 3–5 days.',
            'link_text' => 'Track your order',
            'link_url' => 'https://www.jumia.com.ng/customer/order/tracking',
            'attachment' => '',
            'phishing' => false,
            'why' => 'Matches an order you made, comes from the shop’s real domain, and the link goes to that domain too.',
        ],
        'scholarship' => [
            'from' => 'Federal Scholarship Board',
            'address' => 'fsb.awards2026@outlook.com',
            'subject' => 'You have been awarded a N500,000 scholarship',
            'body' => 'Congratulations! To receive your award, pay a N5,000 processing fee and send a copy of your bank card.',
            'link_text' => 'Accept award',
            'link_url' => 'http://fsb-awards-2026.top/accept',
            'attachment' => '',
            'phishing' => true,
            'why' => 'You did not apply, a government board does not use Outlook, and real awards never charge a fee or ask for your card.',
        ],
        'it-maintenance' => [
            'from' => 'ICT Unit',
            'address' => 'ict@yourschool.edu.ng',
            'subject' => 'Wi-Fi maintenance on Saturday',
            'body' => 'The school Wi-Fi will be off on Saturday from 8am to noon for maintenance. No action is needed.',
            'link_text' => '',
            'link_url' => '',
            'attachment' => '',
            'phishing' => false,
            'why' => 'Informational, from the school’s domain, and asks you to do nothing.',
        ],
    ];

    /** "phishing" or "safe": the answer for one email in the pool. */
    public static function answerFor(string $id): string
    {
        return (self::POOL[$id]['phishing'] ?? false) ? 'phishing' : 'safe';
    }

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'phishing',
            version: 1,
            title: 'Spot the phish',
            subject: 'Cybersecurity',
            track: 'skills',
            level: 'Beginner',
            summary: 'Go through an inbox and decide which emails are phishing and which are safe.',
            objective: 'Recognise the signs of phishing: odd sender addresses, urgency, requests for secrets or payment, and links that go somewhere unexpected.',
            tasks: [
                'Open each email and look at who really sent it and where its link goes.',
                'Mark every email as safe or phishing.',
                'Check your answers to see why each one is (or is not) a trap.',
            ],
            prerequisites: 'None: everyone who uses email should try this.',
            minutes: 20,
        );
    }

    public function params(Seed $seed): array
    {
        $phishing = array_keys(array_filter(self::POOL, static fn (array $e): bool => $e['phishing']));
        $safe = array_keys(array_filter(self::POOL, static fn (array $e): bool => !$e['phishing']));
        $phishCount = $seed->int(3, 5);
        $ids = $seed->shuffle([
            ...array_slice($seed->shuffle($phishing), 0, $phishCount),
            ...array_slice($seed->shuffle($safe), 0, self::PICK - $phishCount),
        ]);

        return [
            'public' => ['emails' => array_map(static fn (string $id): array => [
                'from_name' => self::POOL[$id]['from'],
                'from_address' => self::POOL[$id]['address'],
                'subject' => self::POOL[$id]['subject'],
                'body' => self::POOL[$id]['body'],
                'link_text' => self::POOL[$id]['link_text'],
                'link_url' => self::POOL[$id]['link_url'],
                'attachment' => self::POOL[$id]['attachment'],
            ], $ids)],
            'secret' => ['ids' => $ids],
        ];
    }

    public function fields(array $public): array
    {
        $fields = [];
        foreach ((array) $public['emails'] as $i => $email) {
            $fields[] = new LabField(
                'email_' . ($i + 1),
                sprintf('%d. %s', $i + 1, (string) $email['subject']),
                'select',
                '',
                'From ' . (string) $email['from_name'],
                ['' => 'Choose', 'safe' => 'Safe', 'phishing' => 'Phishing'],
            );
        }

        return $fields;
    }

    public function check(array $params, array $answers): LabResult
    {
        $parts = [];
        foreach ((array) $params['secret']['ids'] as $i => $id) {
            $email = self::POOL[(string) $id];
            $answer = $answers['email_' . ($i + 1)] ?? '';
            $parts[] = [
                'label' => sprintf('%d. %s', $i + 1, $email['subject']),
                'ok' => $answer === self::answerFor((string) $id),
                'detail' => ($email['phishing'] ? 'Phishing. ' : 'Safe. ') . $email['why'],
                'marks' => 1,
            ];
        }
        $given = [];
        foreach ($answers as $name => $value) {
            if (preg_match('/^email_\d{1,2}$/', $name) === 1) {
                $given[$name] = mb_substr($value, 0, 10);
            }
        }

        return LabResult::fromParts($parts, $given);
    }
}
