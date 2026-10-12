<?php

declare(strict_types=1);

namespace App\Modules\Identity;

enum Permission: string
{
    case CourseView = 'course.view';
    case CourseLearn = 'course.learn';
    case CourseManage = 'course.manage';
    case MembersManage = 'members.manage';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
}
