<?php

declare(strict_types=1);

namespace App\Modules\Api\Http;

use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Modules\Tenancy\Tenant;

/**
 * API v1 (ADR 0036): read-only JSON for the school's own systems. Every query is
 * limited to the school the key belongs to. Lists are paged: ?page=1&per_page=50
 * (at most 100). Times are UTC in ISO 8601; money is in minor units (kobo, cents).
 * docs/api.md describes every field.
 */
final class ApiController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly Connection $db)
    {
    }

    public function index(Request $request): Response
    {
        $tenant = self::tenant($request);
        $base = RequestContext::baseUrl($request);

        return Response::json([
            'school' => ['name' => $tenant->name, 'slug' => $tenant->slug, 'timezone' => $tenant->timezone, 'url' => $base],
            'version' => 1,
            'endpoints' => array_map(static fn (string $p): string => $base . '/api/v1/' . $p, ['courses', 'learners', 'enrolments', 'payments', 'certificates']),
            'docs' => 'https://github.com/BankMoney24/dml-lms/blob/main/docs/api.md',
        ]);
    }

    public function courses(Request $request): Response
    {
        $tenant = self::tenant($request);
        $where = ['c.tenant_id = ?'];
        $params = [$tenant->id];
        $status = $request->query('status');
        if (in_array($status, ['published', 'draft'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $base = RequestContext::baseUrl($request);

        $select = 'SELECT c.id, c.slug, c.title, c.summary, c.status, c.track, c.level, c.access, c.price_minor, c.currency, c.duration_hours, c.updated_at,
                    (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = c.tenant_id AND l.course_id = c.id) AS lessons';
        $map = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'summary' => (string) $r['summary'],
            'status' => (string) $r['status'],
            'track' => $r['track'] === null ? null : (string) $r['track'],
            'level' => $r['level'] === null ? null : (string) $r['level'],
            'open_to_everyone' => $r['access'] === 'open',
            'price' => $r['price_minor'] !== null && (int) $r['price_minor'] > 0 && $r['currency'] !== null
                ? ['amount_minor' => (int) $r['price_minor'], 'currency' => (string) $r['currency']] : null,
            'duration_hours' => $r['duration_hours'] === null ? null : (int) $r['duration_hours'],
            'lessons' => (int) $r['lessons'],
            'url' => $base . '/courses/' . rawurlencode((string) $r['slug']),
            'updated_at' => self::iso($r['updated_at']),
        ];

        return $this->page($request, 'FROM courses c WHERE ' . implode(' AND ', $where), $params, $select, 'c.id', $map);
    }

    public function learners(Request $request): Response
    {
        $tenant = self::tenant($request);

        $select = 'SELECT u.id, u.display_name, u.email, m.status, m.created_at';
        $map = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['display_name'],
            'email' => (string) $r['email'],
            'status' => (string) $r['status'],
            'joined_at' => self::iso($r['created_at']),
        ];

        return $this->page($request, "FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.role = 'learner'", [$tenant->id], $select, 'u.id', $map);
    }

    public function enrolments(Request $request): Response
    {
        $tenant = self::tenant($request);
        $where = ['e.tenant_id = ?'];
        $params = [$tenant->id];
        foreach (['course' => 'e.course_id', 'learner' => 'e.user_id'] as $q => $column) {
            $value = (string) ($request->query($q) ?? '');
            if (ctype_digit($value) && strlen($value) < 12) {
                $where[] = $column . ' = ?';
                $params[] = (int) $value;
            }
        }
        $status = $request->query('status');
        if (in_array($status, ['active', 'completed', 'withdrawn'], true)) {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }

        $select = "SELECT e.id, e.user_id, e.course_id, e.status, e.enrolled_at, e.completed_at,
                    (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = e.tenant_id AND l.course_id = e.course_id) AS total,
                    (SELECT COUNT(*) FROM lesson_progress p WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = 'completed') AS done";
        $map = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'learner_id' => (int) $r['user_id'],
            'course_id' => (int) $r['course_id'],
            'status' => (string) $r['status'],
            'enrolled_at' => self::iso($r['enrolled_at']),
            'completed_at' => self::iso($r['completed_at']),
            'lessons_completed' => (int) $r['done'],
            'lessons_total' => (int) $r['total'],
            'progress_percent' => (int) $r['total'] === 0 ? 0 : (int) floor(min((int) $r['done'], (int) $r['total']) * 100 / (int) $r['total']),
        ];

        return $this->page($request, 'FROM enrolments e WHERE ' . implode(' AND ', $where), $params, $select, 'e.id', $map);
    }

    public function payments(Request $request): Response
    {
        $tenant = self::tenant($request);
        $where = ['p.tenant_id = ?'];
        $params = [$tenant->id];
        $status = $request->query('status');
        if (in_array($status, ['pending', 'paid', 'failed', 'refunded'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        $since = (string) ($request->query('since') ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1 && strtotime($since . ' 00:00:00 UTC') !== false) {
            $where[] = 'p.created_at >= ?';
            $params[] = $since . ' 00:00:00';
        }

        $select = 'SELECT p.id, p.reference, p.user_id, p.course_id, p.amount_minor, p.currency, p.status, p.provider, p.mode, p.paid_at, p.created_at';
        $map = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'reference' => (string) $r['reference'],
            'learner_id' => (int) $r['user_id'],
            'course_id' => (int) $r['course_id'],
            'amount_minor' => (int) $r['amount_minor'],
            'currency' => (string) $r['currency'],
            'status' => (string) $r['status'],
            'method' => (string) $r['provider'],
            'test' => ($r['mode'] ?? '') === 'test',
            'paid_at' => self::iso($r['paid_at']),
            'created_at' => self::iso($r['created_at']),
        ];

        return $this->page($request, 'FROM payments p WHERE ' . implode(' AND ', $where), $params, $select, 'p.id', $map);
    }

    public function certificates(Request $request): Response
    {
        $tenant = self::tenant($request);
        $base = RequestContext::baseUrl($request);

        $select = 'SELECT c.code, c.user_id, c.course_id, c.learner_name, c.course_title, c.issued_at, c.revoked_at';
        $map = static fn (array $r): array => [
            'code' => (string) $r['code'],
            'learner_id' => (int) $r['user_id'],
            'course_id' => (int) $r['course_id'],
            'learner_name' => (string) $r['learner_name'],
            'course_title' => (string) $r['course_title'],
            'issued_at' => self::iso($r['issued_at']),
            'revoked_at' => self::iso($r['revoked_at']),
            'verify_url' => $base . '/verify/' . rawurlencode((string) $r['code']),
        ];

        return $this->page($request, 'FROM certificates c WHERE c.tenant_id = ?', [$tenant->id], $select, 'c.id', $map);
    }

    /**
     * One page of a list, newest id first, with the total and a link to the next page.
     *
     * @param list<int|string>                         $params
     * @param callable(array<string, mixed>): array<string, mixed> $map
     */
    private function page(Request $request, string $from, array $params, string $select, string $order, callable $map): Response
    {
        $perPage = (string) ($request->query('per_page') ?? '');
        $perPage = ctype_digit($perPage) ? max(1, min(self::MAX_PER_PAGE, (int) $perPage)) : 50;
        $page = (string) ($request->query('page') ?? '');
        $page = ctype_digit($page) && strlen($page) < 7 ? max(1, (int) $page) : 1;
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) ' . $from, $params);
        $rows = $this->db->fetchAll($select . ' ' . $from . ' ORDER BY ' . $order . ' DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        $query = [];
        parse_str($request->queryString(), $query);
        $next = null;
        if ($page * $perPage < $total) {
            $query['page'] = (string) ($page + 1);
            $next = RequestContext::baseUrl($request) . $request->path . '?' . http_build_query($query);
        }

        return Response::json(['data' => array_map($map, $rows), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'next' => $next]);
    }

    private static function iso(mixed $at): ?string
    {
        return $at === null || $at === '' ? null : str_replace(' ', 'T', (string) $at) . 'Z';
    }

    private static function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \App\Core\Http\HttpException(404, 'Not found.');
        }

        return $tenant;
    }
}
