<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Courses\CoursePackages;
use App\Modules\Courses\CourseRepository;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class FeaturesStep extends WizardStep
{
    public function __construct(private readonly CoursePackages $packages, private readonly CourseRepository $courses)
    {
    }

    public function key(): string
    {
        return 'features';
    }

    public function title(): string
    {
        return 'Learning features';
    }

    public function why(): string
    {
        return 'Turn on what your school will use. Starter courses are added as drafts, so your teachers can adjust them before learners see them.';
    }

    public function view(): string
    {
        return 'school/features';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        return [
            'certificates' => $ctx->tenant->certificatesEnabled,
            'public_catalogue' => $ctx->tenant->publicCatalogue,
            'packages' => [],
        ];
    }

    public function read(Request $request): array
    {
        return [
            'certificates' => $request->input('certificates') === '1',
            'public_catalogue' => $request->input('public_catalogue') === '1',
            'packages' => array_values($request->inputArray('packages')),
        ];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $available = array_column($this->available($ctx), 'name');
        $chosen = [];
        foreach (is_array($raw['packages'] ?? null) ? $raw['packages'] : [] as $name) {
            if (!in_array($name, $available, true)) {
                throw ValidationException::single('packages', 'One of the starter courses is no longer available. Choose again.');
            }
            $chosen[] = (string) $name;
        }

        return [
            'certificates' => (bool) ($raw['certificates'] ?? false),
            'public_catalogue' => (bool) ($raw['public_catalogue'] ?? false),
            'packages' => array_values(array_unique($chosen)),
        ];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $titles = [];
        foreach ($this->packages->all() as $package) {
            if (in_array($package['name'], (array) ($data['packages'] ?? []), true)) {
                $titles[] = $package['title'];
            }
        }

        return [
            ['Certificates', ($data['certificates'] ?? true) ? 'Issued automatically when a learner completes a course' : 'Off'],
            ['Course list', ($data['public_catalogue'] ?? true) ? 'Anyone visiting the site can browse it' : 'Only people who sign in can see it'],
            ['Starter courses', $titles === [] ? 'None' : implode(', ', $titles) . ' (added as drafts)'],
        ];
    }

    public function viewData(WizardContext $ctx, array $values, array $all): array
    {
        return ['packages' => $this->available($ctx), 'installed' => $this->installed($ctx)];
    }

    /**
     * Packages not yet in this school (matched by course title).
     *
     * @return list<array{name: string, title: string, summary: string, level: string, hours: ?int, lessons: int}>
     */
    private function available(WizardContext $ctx): array
    {
        $have = $this->courses->titles($ctx->tenant->id);

        return array_values(array_filter($this->packages->all(), static fn (array $p): bool => !in_array($p['title'], $have, true)));
    }

    /** @return list<string> titles of packages the school already has */
    private function installed(WizardContext $ctx): array
    {
        $have = $this->courses->titles($ctx->tenant->id);

        return array_values(array_map(
            static fn (array $p): string => $p['title'],
            array_filter($this->packages->all(), static fn (array $p): bool => in_array($p['title'], $have, true)),
        ));
    }
}
