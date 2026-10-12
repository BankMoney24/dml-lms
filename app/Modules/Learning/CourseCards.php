<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Database\Connection;
use App\Modules\Courses\Course;

/**
 * What a course card shows: the course, its modules as nodes on a trace
 * (each with its lesson count and, for a signed-in learner, how many are
 * done), and the learner's overall progress. Two queries for any number
 * of cards.
 */
final class CourseCards
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<Course> $courses
     * @return list<array{course: Course, modules: list<array{title: string, lessons: int, done: int}>, lessons: int, done: int, enrolled: bool, completed: bool}>
     */
    public function build(int $tenantId, array $courses, ?int $userId = null): array
    {
        if ($courses === []) {
            return [];
        }
        $ids = array_map(static fn (Course $c): int => $c->id, $courses);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $modules = [];
        foreach (
            $this->db->fetchAll(
                "SELECT m.course_id, m.id, m.title,
                    (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = m.tenant_id AND l.module_id = m.id AND l.is_mandatory = 1) AS lessons
             FROM course_modules m WHERE m.tenant_id = ? AND m.course_id IN ($in) ORDER BY m.position, m.id",
                array_merge([$tenantId], $ids),
            ) as $row
        ) {
            $modules[(int) $row['course_id']][(int) $row['id']] = ['title' => (string) $row['title'], 'lessons' => (int) $row['lessons'], 'done' => 0];
        }

        $enrolments = [];
        if ($userId !== null) {
            foreach (
                $this->db->fetchAll(
                    "SELECT course_id, status FROM enrolments WHERE tenant_id = ? AND user_id = ? AND course_id IN ($in)",
                    array_merge([$tenantId, $userId], $ids),
                ) as $row
            ) {
                $enrolments[(int) $row['course_id']] = (string) $row['status'];
            }
            foreach (
                $this->db->fetchAll(
                    "SELECT e.course_id, l.module_id, COUNT(*) AS done
                 FROM enrolments e
                 JOIN lesson_progress p ON p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = 'completed'
                 JOIN lessons l ON l.tenant_id = p.tenant_id AND l.id = p.lesson_id AND l.is_mandatory = 1
                 WHERE e.tenant_id = ? AND e.user_id = ? AND e.course_id IN ($in)
                 GROUP BY e.course_id, l.module_id",
                    array_merge([$tenantId, $userId], $ids),
                ) as $row
            ) {
                $courseId = (int) $row['course_id'];
                $moduleId = (int) $row['module_id'];
                if (isset($modules[$courseId][$moduleId])) {
                    $modules[$courseId][$moduleId]['done'] = (int) $row['done'];
                }
            }
        }

        $cards = [];
        foreach ($courses as $course) {
            $list = array_values($modules[$course->id] ?? []);
            $cards[] = [
                'course' => $course,
                'modules' => $list,
                'lessons' => array_sum(array_column($list, 'lessons')),
                'done' => array_sum(array_column($list, 'done')),
                'enrolled' => isset($enrolments[$course->id]),
                'completed' => ($enrolments[$course->id] ?? '') === Enrolment::STATUS_COMPLETED,
            ];
        }

        return $cards;
    }
}
