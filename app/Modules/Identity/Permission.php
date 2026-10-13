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
    case SchoolManage = 'school.manage';
    /** Create classes or cohorts, choose their learners and teachers. Teachers of a group act on that group only. */
    case GroupsManage = 'groups.manage';
}
