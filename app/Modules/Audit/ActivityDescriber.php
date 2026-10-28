<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Identity\Role;

/** Turns stored audit actions into plain sentences for the Activity page. */
final class ActivityDescriber
{
    /** Kinds of activity for the page's filter: key => [label, action patterns (SQL LIKE)]. */
    public const KINDS = [
        'signins' => ['Sign-ins and accounts', ['auth.%', 'account.%']],
        'warnings' => ['Warnings', ['auth.login_failed', 'auth.login_locked', 'auth.second_step_failed', 'auth.join_password_failed', 'file.rejected_infected', 'member.two_step_reset']],
        'people' => ['People and joining', ['member.%', 'join.%', 'guardian.%', 'application.%', 'group.%']],
        'teaching' => ['Courses and teaching', ['course.%', 'lesson.%', 'path.created', 'path.updated', 'path.deleted', 'qa.%', 'assignment.reviewed', 'class.%', 'meeting.%']],
        'learning' => ['Learning', ['learning.%', 'assessment.%', 'assignment.submitted', 'certificate.%', 'lab.%', 'path.started']],
        'payments' => ['Payments', ['payment.%', 'payments.%', 'coupon.%']],
        'school' => ['School and website', ['school.%', 'site.%', 'blog.%', 'wizard.%', 'classroom.%', 'reports.%', 'support.%', 'sample.%', 'platform.%', 'api.%']],
    ];

    /**
     * @param array{action: string, actor: ?string, subject_name: ?string, metadata: array<string, mixed>} $event
     * @return array{who: string, what: string, warning: bool}
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
            'auth.reset_requested' => 'asked for a password reset link',
            'auth.email_confirmed' => 'confirmed their email address',
            'auth.second_step_failed' => 'entered a wrong two-step sign-in code',
            'auth.join_password_failed' => 'tried to join with an existing account and a wrong password',
            'account.two_step_enabled' => 'turned on two-step sign-in',
            'account.two_step_disabled' => 'turned off two-step sign-in',
            'account.recovery_codes_renewed' => 'made new recovery codes',
            'member.self_registered' => 'joined the school',
            'member.link_sent' => sprintf('sent %s a sign-in link', $subject),
            'member.two_step_reset' => sprintf('reset two-step sign-in for %s', $subject),
            'join.guardian_requested' => 'asked to follow a child’s progress',
            'join.teach_requested' => 'asked to teach',
            'join.request_approved' => 'approved a request to join',
            'join.request_declined' => 'declined a request to join',
            'guardian.linked' => 'linked a parent or guardian to a learner',
            'guardian.unlinked' => 'unlinked a parent or guardian',
            'guardian.permissions' => 'changed what a parent or guardian may see',
            'application.created' => sprintf('applied for a place on %s', $subject),
            'application.offered' => sprintf('offered a place on %s', $subject),
            'application.declined' => sprintf('declined an application for %s', $subject),
            'group.created' => sprintf('created %s', $subject),
            'group.updated' => sprintf('edited %s', $subject),
            'group.learners_added' => sprintf('added learners to %s', $subject),
            'group.learner_removed' => sprintf('removed a learner from %s', $subject),
            'group.teachers_set' => sprintf('chose the teachers of %s', $subject),
            'group.course_set' => sprintf('gave %s a course', $subject),
            'group.course_removed' => sprintf('took a course away from %s', $subject),
            'group.announced' => sprintf('posted an announcement to %s', $subject),
            'group.announcement_deleted' => sprintf('deleted an announcement in %s', $subject),
            'course.access_changed' => sprintf('changed who can read %s', $subject),
            'course.enrolment_changed' => sprintf('changed how people join %s', $subject),
            'course.completion_changed' => sprintf('changed the completion rule of %s', $subject),
            'course.priced' => sprintf('set a price for %s', $subject),
            'course.price_cleared' => sprintf('made %s free', $subject),
            'lesson.file_added' => sprintf('added a file to %s', $subject),
            'lesson.file_removed' => sprintf('removed a file from %s', $subject),
            'lesson.media_added' => sprintf('added a video or recording to %s', $subject),
            'lesson.media_removed' => sprintf('removed the video or recording from %s', $subject),
            'lesson.required_changed' => sprintf('changed whether %s is required', $subject),
            'path.created' => sprintf('created the learning path %s', $subject),
            'path.updated' => sprintf('edited the learning path %s', $subject),
            'path.deleted' => 'deleted a learning path',
            'path.published' => sprintf('published the learning path %s', $subject),
            'path.started' => sprintf('started the learning path %s', $subject),
            'qa.asked' => sprintf('asked a question under %s', $subject),
            'qa.answered' => sprintf('answered a question under %s', $subject),
            'qa.question_deleted' => 'deleted a question',
            'qa.answer_deleted' => 'deleted an answer',
            'class.scheduled' => sprintf('scheduled a live class for %s', $subject),
            'class.updated' => sprintf('changed the live class %s', $subject),
            'class.cancelled' => sprintf('cancelled the live class %s', $subject),
            'class.started_now' => sprintf('started a live class now: %s', $subject),
            'class.ended' => sprintf('ended the live class %s', $subject),
            'class.register' => sprintf('took the register for %s', $subject),
            'class.material_added' => sprintf('added material to %s', $subject),
            'class.material_removed' => sprintf('removed material from %s', $subject),
            'meeting.started_now' => sprintf('started a meeting: %s', $subject),
            'meeting.invited' => sprintf('invited people to %s', $subject),
            'meeting.cancelled' => sprintf('cancelled the meeting %s', $subject),
            'meeting.ended' => sprintf('ended the meeting %s', $subject),
            'learning.enrolled_by_group' => sprintf('was enrolled in %s with their class', $subject),
            'learning.withdrawn' => sprintf('was withdrawn from %s', $subject),
            'lab.launched' => sprintf('started the lab %s', $subject),
            'lab.submitted' => sprintf('handed in the lab %s (%s%%, %s)', $subject, (string) ($meta['score'] ?? '?'), ($meta['passed'] ?? '') === 'yes' ? 'passed' : 'not passed'),
            'lab.expired' => 'ran out of time in a lab',
            'payment.started' => 'started a payment',
            'payment.paid' => 'paid for a course',
            'payment.recorded' => sprintf('recorded a payment from %s, made by transfer or cash', $subject),
            'payment.claimed' => sprintf('said they paid the school directly for %s', $subject),
            'payment.claim_confirmed' => sprintf('confirmed a direct payment for %s', $subject),
            'payment.claim_rejected' => sprintf('could not find a direct payment for %s and told the learner', $subject),
            'payment.refunded' => 'refunded a payment',
            'payments.connected' => 'connected Paystack',
            'payments.disconnected' => 'disconnected Paystack',
            'payments.instructions_saved' => 'changed how learners pay the school directly',
            'coupon.created' => 'made a discount code',
            'coupon.redeemed' => 'used a discount code',
            'school.created' => 'created the school',
            'school.joining_changed' => 'changed how people join the school',
            'school.join_code_changed' => 'changed the joining code',
            'school.address_added' => 'added a web address for the school',
            'site.page_saved' => sprintf('saved the website page %s', $subject),
            'site.page_published' => sprintf('published the website page %s', $subject),
            'site.page_unpublished' => sprintf('took the website page %s offline', $subject),
            'site.settings_saved' => 'changed the website settings',
            'blog.post_saved' => sprintf('saved the blog post %s', $subject),
            'blog.post_published' => sprintf('published the blog post %s', $subject),
            'blog.post_unpublished' => sprintf('took the blog post %s off the blog', $subject),
            'blog.post_deleted' => 'deleted a draft blog post',
            'wizard.finished' => 'finished setting up the school',
            'classroom.connected' => 'connected the online classroom',
            'classroom.disconnected' => 'disconnected the online classroom',
            'reports.exported' => 'downloaded a report',
            'api.key_created' => sprintf('made an API key for %s', (string) ($meta['name'] ?? 'another system')),
            'api.key_revoked' => sprintf('revoked the API key for %s', (string) ($meta['name'] ?? 'another system')),
            'support.opened' => 'asked for help',
            'support.replied' => 'replied to a help request',
            'support.resolved' => 'closed a help request',
            'file.rejected_infected' => 'uploaded a file that the virus scanner refused',
            'sample.school_created', 'sample.seeded' => 'filled the school with sample data',
            'platform.admin_granted' => sprintf('made %s a platform administrator', $subject),
            'platform.admin_revoked' => sprintf('removed %s as a platform administrator', $subject),
            default => str_replace(['.', '_'], [': ', ' '], $event['action']),
        };

        if ($event['actor'] === null && str_starts_with($event['action'], 'auth.')) {
            $who = 'Unknown account';
        }

        return ['who' => $who, 'what' => $what, 'warning' => in_array($event['action'], self::KINDS['warnings'][1], true)];
    }
}
