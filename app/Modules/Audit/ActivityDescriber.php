<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Identity\Role;

/** Turns stored audit actions into plain sentences for the Activity page. */
final class ActivityDescriber
{
    /**
     * @param array{action: string, actor: ?string, subject_name: ?string, metadata: array<string, mixed>} $event
     * @return array{who: string, what: string}
     */
    public function describe(array $event): array
    {
        $who = $event['actor'] ?? 'Someone';
        $subject = $event['subject_name'] ?? 'a deleted item';
        $meta = $event['metadata'];
        $role = static fn (mixed $value): string => is_string($value) && Role::tryFrom($value) !== null
            ? strtolower(Role::from($value)->label())
            : 'member';

        $what = match ($event['action']) {
            'auth.login' => 'signed in',
            'auth.logout' => 'signed out',
            'auth.login_failed' => 'had a failed sign-in attempt',
            'auth.login_locked' => 'was blocked after repeated failed sign-ins',
            'account.password_changed' => 'changed their password',
            'account.name_changed' => 'changed their display name',
            'member.added' => sprintf('added %s as %s', $subject, $role($meta['role'] ?? null)),
            'member.role_changed' => sprintf('changed %s from %s to %s', $subject, $role($meta['from'] ?? null), $role($meta['to'] ?? null)),
            'member.removed' => sprintf('removed %s', $subject),
            'course.created' => sprintf('created the course %s', $subject),
            'course.updated' => sprintf('edited the details of %s', $subject),
            'course.published' => sprintf('published %s', $subject),
            'course.unpublished' => sprintf('unpublished %s', $subject),
            'lesson.updated' => sprintf('edited the lesson %s', $subject),
            'lesson.deleted' => 'deleted a lesson',
            'learning.enrolled' => sprintf('enrolled in %s', $subject),
            'learning.course_completed' => sprintf('completed %s', $subject),
            'assessment.attempt_submitted' => sprintf(
                'submitted a quiz (%s%%, %s)',
                (string) ($meta['score_percent'] ?? '?'),
                ($meta['passed'] ?? false) ? 'passed' : 'not passed',
            ),
            'assignment.submitted' => 'submitted work for an assignment',
            'assignment.reviewed' => ($meta['decision'] ?? '') === 'approved' ? 'approved an assignment' : 'asked for changes to an assignment',
            'certificate.issued' => 'received a certificate',
            'certificate.revoked' => 'had a certificate revoked',
            'auth.password_reset' => 'reset their password from an emailed link',
            'auth.invitation_accepted' => 'accepted their invitation',
            default => str_replace(['.', '_'], [': ', ' '], $event['action']),
        };

        if ($event['actor'] === null && str_starts_with($event['action'], 'auth.')) {
            $who = 'Unknown account';
        }

        return ['who' => $who, 'what' => $what];
    }
}
