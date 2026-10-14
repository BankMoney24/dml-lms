<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Modules\Tenancy\Tenant;

/**
 * schema.org structured data (ADR 0014, 0019). The school is one
 * EducationalOrganization with a stable @id that every other node points to;
 * only facts the school entered and pages show are used: no addresses,
 * ratings, reviews, dates or accreditation that are not on the site.
 */
final class CourseSchema
{
    /** @return array<string, mixed> */
    public static function organisation(Tenant $tenant, string $baseUrl, ?string $logoUrl): array
    {
        $data = [
            '@type' => 'EducationalOrganization',
            '@id' => $baseUrl . '/#organization',
            'name' => $tenant->name,
            'url' => $baseUrl . '/',
        ];
        if ($tenant->tagline !== '') {
            $data['slogan'] = $tenant->tagline;
        }
        if ($logoUrl !== null) {
            $data['logo'] = ['@type' => 'ImageObject', '@id' => $baseUrl . '/#logo', 'url' => $logoUrl];
        }
        if ($tenant->contactEmail !== '') {
            $data['email'] = $tenant->contactEmail;
        }
        if ($tenant->contactPhone !== '') {
            $data['telephone'] = $tenant->contactPhone;
        }
        if ($tenant->contactEmail !== '' || $tenant->contactPhone !== '') {
            $point = ['@type' => 'ContactPoint', 'contactType' => 'customer service'];
            if ($tenant->contactEmail !== '') {
                $point['email'] = $tenant->contactEmail;
            }
            if ($tenant->contactPhone !== '') {
                $point['telephone'] = $tenant->contactPhone;
            }
            $data['contactPoint'] = [$point];
        }
        // Profiles the school says it controls, and its own main website if that is somewhere else.
        $same = array_values($tenant->socialLinks);
        $site = rtrim($tenant->website, '/');
        if ($site !== '' && parse_url($site, PHP_URL_HOST) !== parse_url($baseUrl, PHP_URL_HOST)) {
            $same[] = $site;
        }
        if ($same !== []) {
            $data['sameAs'] = array_values(array_unique($same));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function website(Tenant $tenant, string $baseUrl): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $baseUrl . '/#website',
            'url' => $baseUrl . '/',
            'name' => $tenant->name,
            'inLanguage' => $tenant->locale,
            'publisher' => ['@id' => $baseUrl . '/#organization'],
        ];
    }

    /**
     * A page about the school (About, Contact, policies, the course list).
     *
     * @return array<string, mixed>
     */
    public static function webPage(string $type, string $name, string $description, string $url, string $baseUrl, ?string $modified = null): array
    {
        $data = [
            '@type' => in_array($type, ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'], true) ? $type : 'WebPage',
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'isPartOf' => ['@id' => $baseUrl . '/#website'],
            'about' => ['@id' => $baseUrl . '/#organization'],
        ];
        if ($modified !== null) {
            $data['dateModified'] = $modified;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function course(Course $course, Tenant $tenant, string $baseUrl): array
    {
        $url = $baseUrl . '/courses/' . $course->slug;
        $data = [
            '@type' => 'Course',
            '@id' => $url . '#course',
            'name' => $course->title,
            'description' => mb_strimwidth($course->summary !== '' ? $course->summary : $course->title, 0, 500, '…'),
            'url' => $url,
            'provider' => ['@type' => 'EducationalOrganization', '@id' => $baseUrl . '/#organization', 'name' => $tenant->name, 'url' => $baseUrl . '/'],
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
        // The price is shown on the course page; free courses say so.
        $data['offers'] = [
            '@type' => 'Offer',
            'category' => $course->isPaid() ? 'Paid' : 'Free',
            'price' => $course->isPaid() ? number_format((int) $course->priceMinor / 100, 2, '.', '') : '0',
            'priceCurrency' => $course->currency ?? 'NGN',
            'url' => $url,
        ];
        $instance = ['@type' => 'CourseInstance', 'courseMode' => 'online'];
        if ($course->durationHours !== null) {
            $instance['courseWorkload'] = 'PT' . $course->durationHours . 'H';
        }
        $data['hasCourseInstance'] = [$instance];

        return $data;
    }
}
