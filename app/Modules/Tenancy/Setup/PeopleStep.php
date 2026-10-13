<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Http\Request;
use App\Core\Mail\MailSettings;
use App\Core\Validation\ValidationException;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

/**
 * Invite the first administrators, educators (and, if wanted, learners) as
 * a pasted list, one person per line. Every line is checked here; nobody
 * is invited until the review step is confirmed (PRD §6.3: review before
 * sending invitations in bulk).
 */
final class PeopleStep extends WizardStep
{
    public const MAX_PEOPLE = 200;

    /** Words accepted in the role column. */
    private const ROLES = [
        'admin' => 'admin', 'administrator' => 'admin',
        'educator' => 'educator', 'teacher' => 'educator', 'lecturer' => 'educator', 'instructor' => 'educator', 'tutor' => 'educator',
        'learner' => 'learner', 'student' => 'learner', 'pupil' => 'learner',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly MailSettings $mail,
    ) {
    }

    public function key(): string
    {
        return 'people';
    }

    public function title(): string
    {
        return 'Invite your team';
    }

    public function why(): string
    {
        return 'Add the administrators and teachers who will set up courses with you. You can add more people, including learners, at any time from People.';
    }

    public function optional(): bool
    {
        return true;
    }

    public function view(): string
    {
        return 'school/people';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        return ['people' => '', 'list' => []];
    }

    public function read(Request $request): array
    {
        return ['people' => $request->input('people', '') ?? ''];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $text = (string) ($raw['people'] ?? '');
        $list = [];
        $problems = [];
        $seen = [];
        $lineNo = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $lineNo++;
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', preg_split('/\s*[,;\t]\s*/u', trim($line)) ?: []);
            $name = (string) preg_replace('/\s+/u', ' ', $parts[0] ?? '');
            $email = UserRepository::normaliseEmail($parts[1] ?? '');
            $roleWord = mb_strtolower($parts[2] ?? 'educator');
            $role = self::ROLES[$roleWord] ?? null;

            if (count($parts) < 2 || count($parts) > 3) {
                $problems[] = sprintf('Line %d: write it as name, email, role.', $lineNo);
            } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                $problems[] = sprintf('Line %d: the name should be 2 to 120 characters.', $lineNo);
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = sprintf('Line %d: "%s" is not an email address.', $lineNo, mb_substr($parts[1], 0, 60));
            } elseif ($role === null) {
                $problems[] = sprintf('Line %d: the role should be admin, educator or learner.', $lineNo);
            } elseif (isset($seen[$email])) {
                $problems[] = sprintf('Line %d: %s is already on line %d.', $lineNo, $email, $seen[$email]);
            } elseif ($this->isMember($ctx, $email)) {
                $problems[] = sprintf('Line %d: %s is already a member here. Remove the line.', $lineNo, $email);
            } else {
                $seen[$email] = $lineNo;
                $list[] = ['name' => $name, 'email' => $email, 'role' => $role];
            }
            if (count($problems) >= 10) {
                break;
            }
        }
        if (count($list) > self::MAX_PEOPLE) {
            $problems[] = sprintf('Invite up to %d people here; add the rest from People after launch.', self::MAX_PEOPLE);
        }
        if ($problems !== []) {
            throw ValidationException::single('people', implode(' ', $problems));
        }

        return ['people' => trim($text), 'list' => $list];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $list = is_array($data['list'] ?? null) ? $data['list'] : [];
        if ($list === []) {
            return [['Invitations', 'Nobody yet']];
        }
        $byRole = [];
        foreach ($list as $person) {
            $byRole[$person['role']] = ($byRole[$person['role']] ?? 0) + 1;
        }
        $counts = [];
        foreach (['admin' => 'admin', 'educator' => 'educator', 'learner' => 'learner'] as $role => $word) {
            if (isset($byRole[$role])) {
                $counts[] = $byRole[$role] . ' ' . $word . ($byRole[$role] === 1 ? '' : 's');
            }
        }
        $names = array_map(static fn (array $p): string => $p['name'], array_slice($list, 0, 6));
        $more = count($list) > 6 ? sprintf(' and %d more', count($list) - 6) : '';

        return [
            ['Invitations', implode(', ', $counts)],
            ['Who', implode(', ', $names) . $more],
            ['How they get in', $this->mail->deliveryAvailable()
                ? 'Each person gets an email with a link to choose their password.'
                : 'Email is not set up, so you will see a one-time password for each person to pass on.'],
        ];
    }

    private function isMember(WizardContext $ctx, string $email): bool
    {
        $user = $this->users->findByEmail($email);
        $membership = $user === null ? null : $this->memberships->find($ctx->tenant->id, $user->id);

        return $membership !== null && $membership->isActive();
    }
}
