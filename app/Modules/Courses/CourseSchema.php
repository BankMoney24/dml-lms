<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Modules\Tenancy\Tenant;

/**
 * schema.org structured data for search engines: the school as an
 * EducationalOrganization and each public course as a Course, so results can
 * show the provider, description and price. Only published facts go in.
 */
final class CourseSchema
{
    /** @return array<string, mixed> */
    public static function organisation(string $name, string $url, string $tagline = '', ?string $logo = null, string $email = '', string $phone = '', string $website = ''): array
    {
        $data = ['@context' => 'https://schema.org', '@type' => 'EducationalOrganization', 'name' => $name, 'url' => $url . '/'];
        if ($tagline !== '') {
            $data['slogan'] = $tagline;
        }
        if ($logo !== null) {
            $data['logo'] = $logo;
        }
        if ($email !== '') {
            $data['email'] = $email;
        }
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        if ($website !== '') {
            $data['sameAs'] = [$website];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function course(Course $course, Tenant $tenant, string $baseUrl): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $course->title,
            'description' => mb_strimwidth($course->summary !== '' ? $course->summary : $course->title, 0, 500, '…'),
            'url' => $baseUrl . '/courses/' . $course->slug,
            'provider' => ['@type' => 'EducationalOrganization', 'name' => $tenant->name, 'url' => $baseUrl . '/'],
            'inLanguage' => 'en',
        ];
        if ($course->levelLabel() !== null) {
            $data['educationalLevel'] = $course->levelLabel();
        }
        if ($course->outcomes !== []) {
            $data['teaches'] = $course->outcomes;
        }
        if ($course->durationHours !== null) {
            $data['timeRequired'] = 'PT' . $course->durationHours . 'H';
        }
        $data['offers'] = [
            '@type' => 'Offer',
            'category' => $course->isPaid() ? 'Paid' : 'Free',
            'price' => $course->isPaid() ? number_format((int) $course->priceMinor / 100, 2, '.', '') : '0',
            'priceCurrency' => $course->currency ?? 'NGN',
        ];
        $data['hasCourseInstance'] = ['@type' => 'CourseInstance', 'courseMode' => 'online', 'courseWorkload' => $course->durationHours !== null ? 'PT' . $course->durationHours . 'H' : 'PT1H'];

        return $data;
    }
}
