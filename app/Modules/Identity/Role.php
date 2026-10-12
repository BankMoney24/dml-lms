<?php

declare(strict_types=1);

namespace App\Modules\Identity;

/** A user's role within one tenant membership (IAM-004). */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Educator = 'educator';
    case Learner = 'learner';
    case Guardian = 'guardian';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => [
                Permission::CourseView,
                Permission::CourseLearn,
                Permission::CourseManage,
                Permission::MembersManage,
                Permission::ReportsView,
                Permission::AuditView,
            ],
            self::Educator => [
                Permission::CourseView,
                Permission::CourseManage,
                Permission::ReportsView,
            ],
            self::Learner => [
                Permission::CourseView,
                Permission::CourseLearn,
            ],
            // Guardian access is record-level via guardian links (Phase 2); no course access by role.
            self::Guardian => [],
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
