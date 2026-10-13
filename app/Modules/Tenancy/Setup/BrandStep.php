<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Tenancy\LogoStore;
use App\Modules\Tenancy\SchoolTheme;
use App\Modules\Tenancy\TenantRepository;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

/**
 * Name, look and public contact details. A logo uploaded here is stored
 * straight away (so it can be previewed) but only becomes the school's logo
 * when the wizard is finished; until then it is "pending" and is removed
 * again if replaced or if the run is discarded.
 */
final class BrandStep extends WizardStep
{
    public function __construct(private readonly LogoStore $logos, private readonly TenantRepository $tenants)
    {
    }

    public function key(): string
    {
        return 'brand';
    }

    public function title(): string
    {
        return 'Name and look';
    }

    public function why(): string
    {
        return 'Learners see this on every page and on their certificates. Contact details appear at the foot of every page so people know how to reach you.';
    }

    public function view(): string
    {
        return 'school/brand';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        $t = $ctx->tenant;

        return [
            'name' => $t->name,
            'tagline' => $t->tagline,
            'theme' => $t->theme,
            'contact_email' => $t->contactEmail,
            'contact_phone' => $t->contactPhone,
            'website' => $t->website,
            'logo_key' => $t->logoKey,
            'logo_type' => $t->logoType,
        ];
    }

    public function read(Request $request): array
    {
        $raw = [];
        foreach (['name', 'tagline', 'theme', 'contact_email', 'contact_phone', 'website'] as $field) {
            $raw[$field] = trim((string) preg_replace('/\s+/u', ' ', $request->input($field, '') ?? ''));
        }
        $raw['remove_logo'] = $request->input('remove_logo') === '1';

        return $raw;
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $errors = [];
        $name = (string) $raw['name'];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter the name of your institution (2 to 120 characters).';
        }
        $tagline = (string) $raw['tagline'];
        if (mb_strlen($tagline) > 160) {
            $errors['tagline'] = 'Keep the tagline under 160 characters.';
        }
        $theme = (string) $raw['theme'];
        if (!SchoolTheme::exists($theme)) {
            $errors['theme'] = 'Choose one of the colours.';
        }
        $email = mb_strtolower((string) $raw['contact_email']);
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254)) {
            $errors['contact_email'] = 'Enter an email address like office@yourschool.ng, or leave it empty.';
        }
        $phone = (string) $raw['contact_phone'];
        if ($phone !== '' && preg_match('/^\+?[0-9 ()\-]{7,25}$/', $phone) !== 1) {
            $errors['contact_phone'] = 'Enter a phone number like +234 801 234 5678, or leave it empty.';
        }
        $website = self::website((string) $raw['website']);
        if ($website === null) {
            $errors['website'] = 'Enter a web address like https://yourschool.ng, or leave it empty.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $previous = $all['brand'] ?? $this->initial($ctx, $all);
        $logoKey = is_string($previous['logo_key'] ?? null) ? $previous['logo_key'] : null;
        $logoType = is_string($previous['logo_type'] ?? null) ? $previous['logo_type'] : null;
        $upload = $ctx->request->file('logo');
        if ($upload !== null) {
            $stored = $this->logos->store($ctx->tenant->id, $upload);
            $this->dropIfPending($ctx, $logoKey);
            [$logoKey, $logoType] = [$stored['key'], $stored['type']];
        } elseif ($raw['remove_logo'] === true) {
            $this->dropIfPending($ctx, $logoKey);
            [$logoKey, $logoType] = [null, null];
        }

        return [
            'name' => $name,
            'tagline' => $tagline,
            'theme' => $theme,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'website' => $website,
            'logo_key' => $logoKey,
            'logo_type' => $logoType,
        ];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $logo = $data['logo_key'] ?? null;

        return [
            ['Name', (string) ($data['name'] ?? '')],
            ['Tagline', ($data['tagline'] ?? '') !== '' ? (string) $data['tagline'] : 'None'],
            ['Colour', SchoolTheme::label((string) ($data['theme'] ?? ''))],
            ['Logo', $logo === null ? 'None, the name is shown instead' : ($logo === $ctx->tenant->logoKey ? 'Current logo' : 'New logo uploaded')],
            ['Email', ($data['contact_email'] ?? '') !== '' ? (string) $data['contact_email'] : 'Not given'],
            ['Phone', ($data['contact_phone'] ?? '') !== '' ? (string) $data['contact_phone'] : 'Not given'],
            ['Website', ($data['website'] ?? '') !== '' ? (string) $data['website'] : 'Not given'],
        ];
    }

    public function viewData(WizardContext $ctx, array $values, array $all): array
    {
        $saved = $all['brand'] ?? $this->initial($ctx, $all);
        $key = is_string($saved['logo_key'] ?? null) ? $saved['logo_key'] : null;
        $preview = null;
        if ($key !== null) {
            $preview = $key === $ctx->tenant->logoKey
                ? '/logo?v=' . $ctx->tenant->logoVersion()
                : '/school/logo-preview?v=' . substr(hash('sha256', $key), 0, 10);
        }

        return [
            'themes' => SchoolTheme::options(),
            'domains' => $this->tenants->domains($ctx->tenant->id),
            'logoPreview' => $preview,
            'maxLogoKb' => (int) (LogoStore::MAX_BYTES / 1024),
        ];
    }

    /** "" stays empty; "yourschool.ng" becomes "https://yourschool.ng"; anything else invalid is null. */
    public static function website(string $value): ?string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = (string) parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !str_contains($host, '.') || strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    private function dropIfPending(WizardContext $ctx, ?string $key): void
    {
        if ($key !== null && $key !== $ctx->tenant->logoKey && LogoStore::belongsTo($key, $ctx->tenant->id)) {
            $this->logos->delete($key);
        }
    }
}
