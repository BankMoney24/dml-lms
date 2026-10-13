<?php

declare(strict_types=1);

namespace App\Modules\Courses;

/**
 * Ready-made courses shipped with each release in courses/<name>/course.json.
 * Used by the console (course:packages, course:import) and by the school
 * setup wizard's "starter courses".
 */
final class CoursePackages
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<array{name: string, title: string, summary: string, level: string, hours: ?int, lessons: int}>
     */
    public function all(): array
    {
        $packages = [];
        foreach (glob($this->directory . '/*/course.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !is_string($data['title'] ?? null)) {
                continue;
            }
            $lessons = 0;
            foreach (is_array($data['modules'] ?? null) ? $data['modules'] : [] as $module) {
                $lessons += is_array($module) && is_array($module['lessons'] ?? null) ? count($module['lessons']) : 0;
            }
            $packages[] = [
                'name' => basename(dirname($file)),
                'title' => $data['title'],
                'summary' => is_string($data['summary'] ?? null) ? $data['summary'] : '',
                'level' => is_string($data['level'] ?? null) ? $data['level'] : '',
                'hours' => is_int($data['duration_hours'] ?? null) ? $data['duration_hours'] : null,
                'lessons' => $lessons,
            ];
        }
        usort($packages, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $packages;
    }

    /** Folder of a package by name, or null. Names are folder names, never paths. */
    public function directory(string $name): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $name) !== 1) {
            return null;
        }
        $directory = $this->directory . '/' . $name;

        return is_file($directory . '/course.json') ? $directory : null;
    }
}
