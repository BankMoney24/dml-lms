<?php

declare(strict_types=1);

namespace App\Modules\Groups;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Classes, cohorts or groups (ADR 0013).
 *
 * Administrators (groups.manage) create groups and choose their teachers.
 * Administrators and the group's own teachers add learners and set courses.
 * Learners in a group are enrolled in its courses, including paid ones: an
 * administrator setting a paid course for a group means the school covers it,
 * so only administrators may do that. Nobody else can see a group exists.
 */
final class GroupService
{
    public const MAX_NAME = 80;
    public const MAX_ADD_AT_ONCE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly GroupRepository $groups,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ?Notifier $notifier = null,
        private readonly ?\App\Modules\Guardians\GuardianRepository $guardians = null,
    ) {
    }

    public function managesAll(CurrentUser $user): bool
    {
        return $user->can(Permission::GroupsManage);
    }

    /** May see the Classes pages at all: administrators and anyone who builds courses. */
    public function mayOpenList(CurrentUser $user): bool
    {
        return $this->managesAll($user) || $user->can(Permission::CourseManage);
    }

    /** The group, if this person may see it; "not found" otherwise, so its existence is not revealed. */
    public function groupFor(Tenant $tenant, int $groupId, CurrentUser $user): LearnerGroup
    {
        $group = $this->groups->find($tenant->id, $groupId);
        if ($group === null || !($this->managesAll($user) || $this->groups->isTeacher($tenant->id, $group->id, $user->user->id))) {
            throw HttpException::notFound();
        }

        return $group;
    }

    /** @param array{name?: string, level?: string, period?: string} $input */
    public function create(Tenant $tenant, CurrentUser $admin, array $input, string $clientIp): int
    {
        $admin->authorise(Permission::GroupsManage);
        [$name, $level, $period] = $this->validDetails($tenant, $input, 0);
        $id = $this->groups->create($tenant->id, $name, $level, $period, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'group.created', 'group', $id, ['name' => $name], $clientIp);

        return $id;
    }

    /** @param array{name?: string, level?: string, period?: string} $input */
    public function update(Tenant $tenant, LearnerGroup $group, CurrentUser $admin, array $input, string $clientIp): void
    {
        $admin->authorise(Permission::GroupsManage);
        [$name, $level, $period] = $this->validDetails($tenant, $input, $group->id);
        $this->groups->update($tenant->id, $group->id, $name, $level, $period);
        $this->audit->record($tenant->id, $admin->user->id, 'group.updated', 'group', $group->id, [], $clientIp);
    }

    public function setArchived(Tenant $tenant, LearnerGroup $group, CurrentUser $admin, bool $archived, string $clientIp): void
    {
        $admin->authorise(Permission::GroupsManage);
        $this->groups->setArchived($tenant->id, $group->id, $archived);
        $this->audit->record($tenant->id, $admin->user->id, $archived ? 'group.archived' : 'group.restored', 'group', $group->id, [], $clientIp);
    }

    /**
     * Adds learners by email (one per line, or separated by commas) and/or by
     * ticking them in a list. Only learners of this school can join.
     *
     * @param list<int> $userIds
     * @return array{added: list<string>, already: list<string>, problems: list<string>, enrolments: int}
     */
    public function addLearners(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, string $emails, array $userIds, string $clientIp): array
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $result = ['added' => [], 'already' => [], 'problems' => [], 'enrolments' => 0];
        $candidates = [];
        foreach (preg_split('/[\s,;]+/u', $emails) ?: [] as $email) {
            $email = trim($email);
            if ($email === '') {
                continue;
            }
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $result['problems'][] = sprintf('%s: no one with this email belongs to the school. Add them on the People page first.', mb_substr($email, 0, 120));
                continue;
            }
            $candidates[$user->id] = $email;
        }
        foreach ($userIds as $id) {
            $candidates[$id] ??= null;
        }
        if ($candidates === [] && $result['problems'] === []) {
            throw ValidationException::single('emails', 'Type at least one email, or tick learners in the list.');
        }
        if (count($candidates) > self::MAX_ADD_AT_ONCE) {
            throw ValidationException::single('emails', sprintf('Add up to %d learners at a time.', self::MAX_ADD_AT_ONCE));
        }
        $courses = array_column($this->groups->courses($tenant->id, $group->id), 'course');

        foreach ($candidates as $userId => $email) {
            $user = $this->users->findById($userId);
            $membership = $user === null ? null : $this->memberships->find($tenant->id, $userId);
            if ($user === null || $membership === null || !$membership->isActive()) {
                $result['problems'][] = sprintf('%s: not a member of this school.', $email ?? 'Someone you ticked');
                continue;
            }
            if ($membership->role !== Role::Learner) {
                $result['problems'][] = sprintf('%s is staff here, not a learner. Teachers are chosen under Teachers.', $user->displayName);
                continue;
            }
            if ($this->groups->roleOf($tenant->id, $group->id, $userId) === GroupRepository::LEARNER) {
                $result['already'][] = $user->displayName;
                continue;
            }
            $enrolled = $this->db->transaction(function () use ($tenant, $group, $actor, $user, $membership, $courses): int {
                $this->groups->addMember($tenant->id, $group->id, $user->id, GroupRepository::LEARNER, $actor->user->id);
                $count = 0;
                $learner = new CurrentUser($user, $membership);
                foreach ($courses as $course) {
                    $count += $this->learning->enrolForGroup($course, $learner, $actor->user->id, $group->id) ? 1 : 0;
                }

                return $count;
            });
            $result['added'][] = $user->displayName;
            $this->notifier?->notify(
                $tenant->id,
                $user->id,
                Notifier::GROUP_JOINED,
                sprintf('You are now in %s', $group->name),
                $courses === [] ? null : sprintf('Your courses for this %s are on your home page.', $tenant->groupWord()),
                '/',
            );
            $result['enrolments'] += $enrolled;
        }
        if ($result['added'] !== []) {
            $this->audit->record($tenant->id, $actor->user->id, 'group.learners_added', 'group', $group->id, ['count' => count($result['added'])], $clientIp);
        }

        return $result;
    }

    /** Leaving a group keeps the learner's courses and progress; they just stop being counted here. */
    public function removeLearner(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, int $userId, string $clientIp): void
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $this->groups->removeMember($tenant->id, $group->id, $userId, GroupRepository::LEARNER);
        $this->audit->record($tenant->id, $actor->user->id, 'group.learner_removed', 'group', $group->id, ['learner' => $userId], $clientIp);
    }

    /**
     * Replaces the group's teachers. Owners, administrators and educators can teach a group.
     *
     * @param list<int> $userIds
     */
    public function setTeachers(Tenant $tenant, LearnerGroup $group, CurrentUser $admin, array $userIds, string $clientIp): void
    {
        $admin->authorise(Permission::GroupsManage);
        $chosen = [];
        foreach (array_unique($userIds) as $id) {
            $membership = $this->memberships->find($tenant->id, $id);
            if ($membership === null || !$membership->isActive() || !in_array($membership->role, [Role::Owner, Role::Admin, Role::Educator], true)) {
                throw ValidationException::single('teachers', 'Choose teachers from the staff of this school.');
            }
            $chosen[] = $id;
        }
        $this->db->transaction(function () use ($tenant, $group, $admin, $chosen): void {
            foreach ($this->groups->members($tenant->id, $group->id, GroupRepository::TEACHER) as $current) {
                if (!in_array($current['user_id'], $chosen, true)) {
                    $this->groups->removeMember($tenant->id, $group->id, $current['user_id'], GroupRepository::TEACHER);
                }
            }
            foreach ($chosen as $id) {
                if ($this->groups->roleOf($tenant->id, $group->id, $id) !== GroupRepository::TEACHER) {
                    $this->groups->addMember($tenant->id, $group->id, $id, GroupRepository::TEACHER, $admin->user->id);
                    if ($id !== $admin->user->id) {
                        $this->notifier?->notify($tenant->id, $id, Notifier::GROUP_TEACHING, sprintf('You now teach %s', $group->name), null, '/groups/' . $group->id);
                    }
                }
            }
        });
        $this->audit->record($tenant->id, $admin->user->id, 'group.teachers_set', 'group', $group->id, ['count' => count($chosen)], $clientIp);
    }

    /**
     * Sets a course for the group (or changes its due date) and enrols every learner in the group.
     *
     * @return int how many learners were newly enrolled
     */
    public function assignCourse(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, int $courseId, string $dueText, string $clientIp): int
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $errors = [];
        $course = $this->courses->findById($tenant->id, $courseId);
        if ($course === null || !$course->isPublished()) {
            $errors['course_id'] = 'Choose a published course.';
        } elseif ($course->isPaid() && !$this->managesAll($actor)) {
            $errors['course_id'] = 'This course is paid. Ask an administrator to set it for the group; the school then covers it for these learners.';
        }
        $due = $this->validDate($dueText);
        if ($due === false) {
            $errors['due_on'] = 'Enter the date as day, month and year, or leave it blank.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var \App\Modules\Courses\Course $course */
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);
        $wasSet = in_array($course->id, array_map(static fn (array $c): int => $c['course']->id, $this->groups->courses($tenant->id, $group->id)), true);

        $enrolled = $this->db->transaction(function () use ($tenant, $group, $actor, $course, $due, $learners): int {
            $this->groups->assignCourse($tenant->id, $group->id, $course->id, $due ?: null, $actor->user->id);
            $count = 0;
            foreach ($learners as $row) {
                $user = $this->users->findById($row['user_id']);
                $membership = $this->memberships->find($tenant->id, $row['user_id']);
                if ($user !== null && $membership !== null && $this->learning->enrolForGroup($course, new CurrentUser($user, $membership), $actor->user->id, $group->id)) {
                    $count++;
                }
            }

            return $count;
        });
        $this->audit->record($tenant->id, $actor->user->id, 'group.course_set', 'group', $group->id, ['course' => $course->id, 'due' => $due ?: null], $clientIp);
        $this->notifier?->notifyMany(
            $tenant->id,
            array_column($learners, 'user_id'),
            Notifier::COURSE_SET,
            $wasSet ? sprintf('Due date changed: %s', $course->title) : sprintf('New course for %s: %s', $group->name, $course->title),
            $due ? 'Due ' . (new \DateTimeImmutable((string) $due))->format('j F Y') . '.' : ($wasSet ? 'There is no due date now.' : null),
            '/courses/' . $course->slug,
        );

        return $enrolled;
    }

    public const MAX_ANNOUNCEMENT = 2000;

    /** A message from the group's teachers to its learners, on their home page and in their notifications. */
    public function announce(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, string $body, string $clientIp): int
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) < 2) {
            throw ValidationException::single('announcement', 'Write the announcement first.');
        }
        if (mb_strlen($body) > self::MAX_ANNOUNCEMENT) {
            throw ValidationException::single('announcement', 'Keep announcements under 2,000 characters.');
        }
        $learners = array_column($this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER), 'user_id');
        $id = $this->db->transaction(function () use ($tenant, $group, $actor, $body, $learners): int {
            $id = $this->groups->addAnnouncement($tenant->id, $group->id, $actor->user->id, $body);
            $this->notifier?->notifyMany(
                $tenant->id,
                $learners,
                Notifier::ANNOUNCEMENT,
                sprintf('%s posted in %s', $actor->user->displayName, $group->name),
                $body,
                '/#my-group-' . $group->id,
            );
            // Guardians who may see class notices hear about it too, by the child's name only.
            foreach ($this->guardians?->noticeRecipients($tenant->id, $learners) ?? [] as $g) {
                $this->notifier?->notify(
                    $tenant->id,
                    $g['guardian_id'],
                    Notifier::ANNOUNCEMENT,
                    sprintf('News from %s\'s %s', explode(' ', trim($g['learner_name']))[0], $tenant->groupWord()),
                    $body,
                    '/children',
                );
            }

            return $id;
        });
        $this->audit->record($tenant->id, $actor->user->id, 'group.announced', 'group', $group->id, ['announcement' => $id, 'learners' => count($learners)], $clientIp);

        return $id;
    }

    public function deleteAnnouncement(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, int $announcementId, string $clientIp): void
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $this->groups->deleteAnnouncement($tenant->id, $group->id, $announcementId);
        $this->audit->record($tenant->id, $actor->user->id, 'group.announcement_deleted', 'group', $group->id, ['announcement' => $announcementId], $clientIp);
    }

    /** Learners keep the course and their progress; it just stops being set for the group. */
    public function unassignCourse(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, int $courseId, string $clientIp): void
    {
        $this->authoriseTeaching($tenant, $group, $actor);
        $this->groups->unassignCourse($tenant->id, $group->id, $courseId);
        $this->audit->record($tenant->id, $actor->user->id, 'group.course_removed', 'group', $group->id, ['course' => $courseId], $clientIp);
    }

    public function mayTeach(Tenant $tenant, LearnerGroup $group, CurrentUser $user): bool
    {
        return $this->managesAll($user) || $this->groups->isTeacher($tenant->id, $group->id, $user->user->id);
    }

    private function authoriseTeaching(Tenant $tenant, LearnerGroup $group, CurrentUser $actor): void
    {
        if (!$this->mayTeach($tenant, $group, $actor)) {
            throw HttpException::forbidden();
        }
        if ($group->isArchived()) {
            throw ValidationException::single('group', sprintf('This %s is archived. Restore it to make changes.', $tenant->groupWord()));
        }
    }

    /**
     * @param array{name?: string, level?: string, period?: string} $input
     * @return array{string, ?string, ?string}
     */
    private function validDetails(Tenant $tenant, array $input, int $groupId): array
    {
        $clean = static fn (string $v): string => trim((string) preg_replace('/\s+/u', ' ', $v));
        $name = $clean($input['name'] ?? '');
        $level = $clean($input['level'] ?? '');
        $period = $clean($input['period'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = sprintf('Give the %s a name, for example “SS 2 Gold”.', $tenant->groupWord());
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = sprintf('Keep the name under %d characters.', self::MAX_NAME);
        } elseif ($this->groups->nameTaken($tenant->id, $name, $groupId)) {
            $errors['name'] = sprintf('There is already a %s called “%s”.', $tenant->groupWord(), $name);
        }
        if (mb_strlen($level) > 60) {
            $errors['level'] = 'Keep the level under 60 characters.';
        }
        if (mb_strlen($period) > 60) {
            $errors['period'] = 'Keep the year or term under 60 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $level === '' ? null : $level, $period === '' ? null : $period];
    }

    /** "2026-10-30" (from a date field) → itself; blank → ''; anything else → false. */
    private function validDate(string $text): string|false
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $text) {
            return false;
        }
        $year = (int) $date->format('Y');
        $now = (int) $this->clock->now()->format('Y');

        return $year < $now - 1 || $year > $now + 5 ? false : $text;
    }
}
