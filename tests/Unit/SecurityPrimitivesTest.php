<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mail\Message;
use App\Core\Mail\MimeBuilder;
use App\Core\Security\SecretBox;
use App\Modules\Identity\TwoFactor\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    public function testTotpMatchesRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'];
        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::codeAt($secret, Totp::step($time)), "t=$time");
        }
    }

    public function testTotpAcceptsOneStepOfDriftAndRefusesReplay(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $previous = Totp::codeAt($secret, Totp::step($now) - 1);

        $step = Totp::verify($secret, $previous, $now, null);
        $this->assertSame(Totp::step($now) - 1, $step);
        $this->assertNull(Totp::verify($secret, $previous, $now, $step), 'same code cannot be used twice');
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, Totp::step($now) - 3), $now, null), 'too old');
        $this->assertNull(Totp::verify($secret, '12345', $now, null));
    }

    public function testSecretBoxRoundTripAndTamperDetection(): void
    {
        $box = new SecretBox(str_repeat('k', 40));
        $sealed = $box->encrypt('JBSWY3DPEHPK3PXP');

        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        $this->assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($sealed));
        $this->assertNull((new SecretBox(str_repeat('x', 40)))->decrypt($sealed), 'other key');
        $raw = (string) base64_decode(substr($sealed, 3), true);
        $raw[30] = chr(ord($raw[30]) ^ 1);
        $tampered = 'v1:' . base64_encode($raw);
        $this->assertNull($box->decrypt($tampered), 'tampered ciphertext');
    }

    public function testMimeMessageIsWellFormed(): void
    {
        $mime = new MimeBuilder('no-reply@school.ng', 'Àdé School');
        $raw = $mime->build(new Message('ada@example.org', 'Ada', 'Ẹ káàbọ̀ — welcome', "Line one\n.leading dot\n" . str_repeat('x', 100), '<p>Hi</p>'));

        $this->assertStringContainsString("Subject: =?UTF-8?B?", $raw);
        $this->assertStringContainsString("From: =?UTF-8?B?", $raw);
        $this->assertStringContainsString("Content-Type: multipart/alternative;", $raw);
        $this->assertStringContainsString("Auto-Submitted: auto-generated", $raw);
        $this->assertStringNotContainsString("\n\r", str_replace("\r\n", '', $raw));
        foreach (explode("\r\n", $raw) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
    }

    public function testMessageRefusesHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message('ada@example.org', "Ada\r\nBcc: evil@example.org", 'Hi', 'x');
    }

    public function testMessageRefusesBadAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message("ada@example.org\r\nBcc: x@y.z", null, 'Hi', 'x');
    }
}
