<?php

declare(strict_types=1);

namespace App\Modules\Search;

use App\Core\Database\Connection;
use App\Modules\Courses\Lesson;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * One search box for the whole school (ADR 0018). Everything stays inside the
 * school and inside what this person may already open: published courses and
 * their lessons for everyone, drafts for course builders, people for member
 * managers, the classes someone teaches or runs, and shortcuts to pages.
 * Matching is a plain, escaped LIKE on titles and names: predictable, and fast
 * enough for a school's catalogue without a search server.
 */
final class SearchService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 80;

    public function __construct(private readonly Connection $db, private readonly ?GroupRepository $groups = null)
    {
    }

    /**
     * @return list<array{label: string, items: list<array{title: string, detail: string, url: string, icon: string}>}>
     */
    public function search(Tenant $tenant, CurrentUser $user, string $query, int $perGroup = 6): array
    {
        $q = self::normalise($query);
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $groups = [
            ['label' => 'Pages', 'items' => $this->pages($tenant, $user, $q)],
            ['label' => 'Courses', 'items' => $this->courses($tenant, $user, $like, $perGroup)],
            ['label' => 'Lessons', 'items' => $this->lessons($tenant, $user, $like, $perGroup + 2)],
            ['label' => $tenant->groupWord(true, true), 'items' => $this->classes($tenant, $user, $q, $perGroup)],
            ['label' => 'People', 'items' => $this->people($tenant, $user, $like, $perGroup)],
        ];

        return array_values(array_filter($groups, static fn (array $g): bool => $g['items'] !== []));
    }

    public static function normalise(string $query): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $query));

        return mb_substr($q, 0, self::MAX_LENGTH);
    }

    /** @return list<array{title: string, detail: string, url: string, icon: string}> */
    private function courses(Tenant $tenant, CurrentUser $user, string $like, int $limit): array
    {
        $builder = $user->can(Permission::CourseManage);
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.slug, c.title, c.status,
                    (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = c.tenant_id AND l.course_id = c.id) AS lessons,
                    e.status AS enrolment
             FROM courses c
             LEFT JOIN enrolments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.user_id = ?
             WHERE c.tenant_id = ? AND (c.title LIKE ? OR c.summary LIKE ?)" . ($builder ? '' : " AND c.status = 'published'") . "
             ORDER BY (c.title LIKE ?) DESC, c.title LIMIT " . $limit,
            [$user->user->id, $tenant->id, $like, $like, $like],
        );

        return array_map(static function (array $r): array {
            $draft = $r['status'] !== 'published';
            $state = match (true) {
                $draft => 'Draft',
                $r['enrolment'] === 'completed' => 'Completed',
                $r['enrolment'] === 'active' => 'In progress',
                default => (int) $r['lessons'] . ((int) $r['lessons'] === 1 ? ' lesson' : ' lessons'),
            };

            return [
                'title' => (string) $r['title'],
                'detail' => 'Course, ' . strtolower($state),
                'url' => $draft ? '/teach/courses/' . (int) $r['id'] : '/courses/' . rawurlencode((string) $r['slug']),
                'icon' => 'courses',
            ];
        }, $rows);
    }

    /** @return list<array{title: string, detail: string, url: string, icon: string}> */
    private function lessons(Tenant $tenant, CurrentUser $user, string $like, int $limit): array
    {
        $rows = $this->db->fetchAll(
            "SELECT l.id, l.title, l.type, l.is_preview, c.slug, c.title AS course_title, e.id AS enrolment_id
             FROM lessons l
             JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id AND c.status = 'published'
             LEFT JOIN enrolments e ON e.tenant_id = l.tenant_id AND e.course_id = l.course_id AND e.user_id = ? AND e.status <> 'withdrawn'
             WHERE l.tenant_id = ? AND l.title LIKE ?
             ORDER BY (e.id IS NULL), (l.title LIKE ?) DESC, c.title, l.position LIMIT " . $limit,
            [$user->user->id, $tenant->id, $like, $like],
        );

        return array_map(static function (array $r): array {
            $slug = '/courses/' . rawurlencode((string) $r['slug']);
            $label = Lesson::TYPES[(string) $r['type']][0] ?? 'Lesson';
            $url = match (true) {
                $r['enrolment_id'] !== null => $slug . '/lessons/' . (int) $r['id'],
                (bool) $r['is_preview'] => $slug . '/preview/' . (int) $r['id'],
                default => $slug,
            };

            return ['title' => (string) $r['title'], 'detail' => $label . ' in ' . $r['course_title'], 'url' => $url, 'icon' => 'lesson'];
        }, $rows);
    }

    /** @return list<array{title: string, detail: string, url: string, icon: string}> */
    private function people(Tenant $tenant, CurrentUser $user, string $like, int $limit): array
    {
        if (!$user->can(Permission::MembersManage)) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.display_name, u.email, m.role FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? AND m.status = 'active' AND (u.display_name LIKE ? OR u.email LIKE ?)
             ORDER BY u.display_name LIMIT " . $limit,
            [$tenant->id, $like, $like],
        );

        return array_map(static fn (array $r): array => [
            'title' => (string) $r['display_name'],
            'detail' => ucfirst((string) $r['role']) . ', ' . $r['email'],
            'url' => '/people?find=' . rawurlencode((string) $r['email']) . '#person-' . (int) $r['id'],
            'icon' => 'people',
        ], $rows);
    }

    /** @return list<array{title: string, detail: string, url: string, icon: string}> */
    private function classes(Tenant $tenant, CurrentUser $user, string $q, int $limit): array
    {
        if ($this->groups === null || !($user->can(Permission::GroupsManage) || $user->can(Permission::CourseManage))) {
            return [];
        }
        $rows = $this->groups->overview($tenant->id, $user->can(Permission::GroupsManage) ? null : $user->user->id);
        $out = [];
        foreach ($rows as $row) {
            if (mb_stripos($row['group']->name, $q) === false) {
                continue;
            }
            $out[] = [
                'title' => $row['group']->name,
                'detail' => sprintf('%s, %d %s', $tenant->groupWord(false, true), $row['learners'], $row['learners'] === 1 ? 'learner' : 'learners'),
                'url' => '/groups/' . $row['group']->id,
                'icon' => 'groups',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Shortcuts to pages, matched on their name and a few everyday words.
     *
     * @return list<array{title: string, detail: string, url: string, icon: string}>
     */
    private function pages(Tenant $tenant, CurrentUser $user, string $q): array
    {
        $pages = [
            ['Home', '/', 'home', 'dashboard start', null],
            ['All courses', '/courses', 'courses', 'catalogue browse', null],
            ['Saved lessons and notes', '/saved', 'saved', 'bookmarks notes', Permission::CourseLearn],
            ['Notifications', '/notifications', 'bell', 'alerts messages', null],
            ['Your account', '/account', 'people', 'profile password name two-step', null],
            ['Calendar link', '/account/calendar', 'calendar', 'due dates deadlines phone', null],
            ['Get help', '/help', 'help', 'support question problem', null],
            ['Check a certificate', '/verify', 'certificate', 'verify', null],
            ['Start a new course', '/teach/courses/new', 'teach', 'create add course', Permission::CourseManage],
            ['Reviews', '/teach/reviews', 'review', 'assignments marking submissions', Permission::CourseManage],
            ['Lab catalogue', '/teach/labs', 'lab', 'labs simulations', Permission::CourseManage],
            [$tenant->groupWord(true, true), '/groups', 'groups', 'class cohort group', Permission::CourseManage],
            ['People', '/people', 'people', 'members add invite learners teachers', Permission::MembersManage],
            ['Help desk', '/school/support', 'inbox', 'support requests', Permission::SupportManage],
            ['Payments', '/school/payments', 'payments', 'paystack money fees', Permission::SchoolManage],
            ['School settings', '/school', 'school', 'settings logo colour theme', Permission::SchoolManage],
            ['Activity', '/activity', 'activity', 'audit log', Permission::AuditView],
        ];
        $out = [];
        foreach ($pages as [$title, $url, $icon, $words, $permission]) {
            if ($permission !== null && !$user->can($permission)) {
                continue;
            }
            if ($url === '/groups' && !($user->can(Permission::GroupsManage) || $user->can(Permission::CourseManage))) {
                continue;
            }
            if (mb_stripos($title . ' ' . $words, $q) !== false) {
                $out[] = ['title' => $title, 'detail' => 'Page', 'url' => $url, 'icon' => $icon];
            }
        }

        return array_slice($out, 0, 4);
    }
}
