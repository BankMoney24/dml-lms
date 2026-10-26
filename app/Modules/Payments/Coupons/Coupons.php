<?php

declare(strict_types=1);

namespace App\Modules\Payments\Coupons;

use App\Core\Clock;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Payments\Money;
use App\Modules\Tenancy\Tenant;

/** Discount codes (ADR 0032): made by school administrators, typed by learners before they pay. */
final class Coupons
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,31}$/';

    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly CourseRepository $courses,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{code?: string, kind?: string, value?: string, course_id?: string, max_uses?: string, expires_on?: string, note?: string} $in
     */
    public function create(Tenant $tenant, CurrentUser $admin, array $in, string $clientIp): Coupon
    {
        $admin->authorise(Permission::SchoolManage);
        $errors = [];
        $code = Coupon::normalise((string) ($in['code'] ?? ''));
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $errors['code'] = 'Use 3 to 32 letters, numbers or dashes, for example EARLYBIRD or STAFF-2026.';
        } elseif ($this->coupons->findByCode($tenant->id, $code) !== null) {
            $errors['code'] = 'This school already has a code ' . $code . '. Choose another.';
        }

        $course = null;
        $courseId = trim((string) ($in['course_id'] ?? ''));
        if ($courseId !== '' && $courseId !== '0') {
            $course = ctype_digit($courseId) ? $this->courses->findById($tenant->id, (int) $courseId) : null;
            if ($course === null || !$course->isPaid()) {
                $errors['course_id'] = 'Choose one of the school’s paid courses, or every paid course.';
            }
        }

        $kind = (string) ($in['kind'] ?? '');
        $value = trim((string) ($in['value'] ?? ''));
        $percent = null;
        $amount = null;
        $currency = null;
        if ($kind === 'percent') {
            if (preg_match('/^\d{1,3}$/', rtrim($value, '%')) !== 1 || (int) $value < 1 || (int) $value > 100) {
                $errors['value'] = 'Enter a percentage from 1 to 100.';
            } else {
                $percent = (int) $value;
            }
        } elseif ($kind === 'amount') {
            $amount = Money::parse($value);
            if ($amount === null || $amount < 100 || $amount > Money::MAX_MINOR) {
                $errors['value'] = 'Enter the amount off, for example 2000.';
                $amount = null;
            }
            $currency = $course->currency ?? $this->schoolCurrency($tenant->id);
            if ($currency === null) {
                $errors['value'] = 'Set a price on a course first, so the code knows its currency.';
            }
        } else {
            $errors['kind'] = 'Choose a percentage or an amount off.';
        }

        $maxUses = null;
        $maxText = trim((string) ($in['max_uses'] ?? ''));
        if ($maxText !== '') {
            if (preg_match('/^\d{1,6}$/', $maxText) !== 1 || (int) $maxText < 1) {
                $errors['max_uses'] = 'Enter how many times it can be used, for example 20, or leave it empty for no limit.';
            } else {
                $maxUses = (int) $maxText;
            }
        }

        $expires = trim((string) ($in['expires_on'] ?? ''));
        $today = $this->today($tenant);
        if ($expires !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if ($d === false || $d->format('Y-m-d') !== $expires) {
                $errors['expires_on'] = 'Choose a date, or leave it empty.';
            } elseif ($expires < $today) {
                $errors['expires_on'] = 'The last day cannot be in the past.';
            }
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['note'] ?? '')));
        if (mb_strlen($note) > 160) {
            $errors['note'] = 'Keep the note under 160 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->coupons->create($tenant->id, [
            'code' => $code, 'course_id' => $course?->id, 'percent_off' => $percent, 'amount_off_minor' => $amount, 'currency' => $currency,
            'max_uses' => $maxUses, 'expires_on' => $expires === '' ? null : $expires, 'note' => $note,
        ], $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'coupon.created', 'coupon', $id, ['code' => $code, 'percent' => $percent, 'amount' => $amount, 'course' => $course?->id], $clientIp);

        return $this->coupons->find($tenant->id, $id) ?? throw new \RuntimeException('Coupon not stored.');
    }

    public function setActive(Tenant $tenant, CurrentUser $admin, int $id, bool $active, string $clientIp): Coupon
    {
        $admin->authorise(Permission::SchoolManage);
        $coupon = $this->coupons->find($tenant->id, $id) ?? throw \App\Core\Http\HttpException::notFound();
        $this->coupons->setStatus($tenant->id, $id, $active ? Coupon::ACTIVE : Coupon::DISABLED);
        $this->audit->record($tenant->id, $admin->user->id, $active ? 'coupon.enabled' : 'coupon.disabled', 'coupon', $id, ['code' => $coupon->code], $clientIp);

        return $coupon;
    }

    /** @return list<Coupon> */
    public function all(Tenant $tenant): array
    {
        return $this->coupons->all($tenant->id);
    }

    /**
     * Checks a code a learner typed for a course. With $lock (inside a transaction),
     * the code's row stays locked until the payment that uses it is stored.
     *
     * @return array{coupon: Coupon, discount: int, price: int}
     * @throws ValidationException (field "code") when the code cannot be used
     */
    public function quote(Tenant $tenant, Course $course, string $code, bool $lock = false): array
    {
        $normal = Coupon::normalise($code);
        $coupon = preg_match(self::CODE_PATTERN, $normal) === 1 ? $this->coupons->findByCode($tenant->id, $normal, $lock) : null;
        if ($coupon === null) {
            throw ValidationException::single('code', 'That code was not found. Check the spelling with whoever gave it to you.');
        }
        $problem = $coupon->problemFor($course, $this->today($tenant));
        if ($problem !== null) {
            throw ValidationException::single('code', $problem);
        }
        $discount = $coupon->discountOn($course);
        $price = max(0, (int) $course->priceMinor - $discount);
        if ($price > 0 && $price < Money::MIN_MINOR) {
            // Paystack does not take very small payments; the school should make the code cover the whole price.
            $least = Money::format(Money::MIN_MINOR, (string) $course->currency);
            throw ValidationException::single('code', 'That code leaves less than ' . $least . ' to pay, which cannot be paid online. Ask the school.');
        }

        return ['coupon' => $coupon, 'discount' => $discount, 'price' => $price];
    }

    private function today(Tenant $tenant): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($tenant->timezone !== '' ? $tenant->timezone : 'UTC'))->format('Y-m-d');
    }

    private function schoolCurrency(int $tenantId): ?string
    {
        foreach ($this->courses->listPaid($tenantId) as $c) {
            return $c->currency;
        }

        return null;
    }
}
