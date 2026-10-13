<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\Course;
use App\Modules\Payments\Money;
use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase
{
    public function testMoneyIsFormattedAndParsedInMinorUnits(): void
    {
        $this->assertSame('₦15,000', Money::format(1500000, 'NGN'));
        $this->assertSame('GH₵250.50', Money::format(25050, 'GHS'));
        $this->assertSame('$49', Money::format(4900, 'USD'));
        $this->assertSame(1500000, Money::parse('15,000'));
        $this->assertSame(1500050, Money::parse('₦ 15000.50'));
        $this->assertNull(Money::parse('fifteen'));
        $this->assertNull(Money::parse('1.234'));
        $this->assertNull(Money::parse(''));
        $this->assertFalse(Money::isSupported('EUR'));
    }

    public function testACourseIsPaidOnlyWithAPriceAndCurrency(): void
    {
        $row = ['id' => 1, 'tenant_id' => 1, 'slug' => 's', 'title' => 'T', 'summary' => '', 'status' => 'published'];
        $this->assertFalse(Course::fromRow($row)->isPaid());
        $this->assertFalse(Course::fromRow($row + ['price_minor' => 0, 'currency' => 'NGN'])->isPaid());
        $paid = Course::fromRow($row + ['price_minor' => 1500000, 'currency' => 'NGN']);
        $this->assertTrue($paid->isPaid());
        $this->assertSame('₦15,000', $paid->priceLabel());
    }

    public function testOnlyPaystackCheckoutPagesAreRedirectTargets(): void
    {
        $this->assertSame('https://checkout.paystack.com/abc123', Response::redirectToCheckout('https://checkout.paystack.com/abc123')->header('Location'));
        foreach (['https://evil.example/abc', 'https://checkout.paystack.com.evil.example/abc', 'http://checkout.paystack.com/abc', 'https://checkout.paystack.com/a?b=c'] as $url) {
            try {
                Response::redirectToCheckout($url);
                $this->fail('accepted ' . $url);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A JSON webhook body is never parsed into $_POST, so it must not look like an oversized form. */
    public function testAJsonBodyIsNotMistakenForAnOversizedForm(): void
    {
        $saved = [$_SERVER, $_POST, $_FILES];
        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/webhooks/paystack', 'HTTP_HOST' => 'school.test', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '120'];
            $_POST = $_FILES = [];
            $this->assertFalse(Request::fromGlobals()->bodyTooLarge);

            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=x';
            $this->assertTrue(Request::fromGlobals()->bodyTooLarge, 'an emptied form post still is');
        } finally {
            [$_SERVER, $_POST, $_FILES] = $saved;
        }
    }
}
