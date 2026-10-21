<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Response;
use App\Modules\Files\UploadPolicy;
use App\Modules\LiveClasses\Classroom\ClassroomSettings;
use App\Modules\LiveClasses\Classroom\JitsiToken;
use PHPUnit\Framework\TestCase;

/** ADR 0029: media kinds and limits, byte ranges, classroom server names and passes. */
final class MediaPolicyTest extends TestCase
{
    private const MEDIA = __DIR__ . '/../Fixtures/media/';

    public function testVideoAndAudioHaveTheirOwnLimit(): void
    {
        $policy = new UploadPolicy(4000, 20000);
        $this->assertSame('video', UploadPolicy::mediaKind('mp4'));
        $this->assertSame('audio', UploadPolicy::mediaKind('m4a'));
        $this->assertNull(UploadPolicy::mediaKind('pdf'));
        $this->assertNull($policy->problemWith(self::MEDIA . 'clip.mp4', 'mp4'), '9.9 KB is under the media limit but over the file limit');
        $this->assertNull($policy->problemWith(self::MEDIA . 'tone.mp3', 'mp3'));
        $this->assertStringContainsString('contents do not match', (string) $policy->problemWith(self::MEDIA . 'tone.mp3', 'mp4'));
        $this->assertStringContainsString('Video and audio can be up to', (string) (new UploadPolicy(1000, 2000))->problemWith(self::MEDIA . 'clip.mp4', 'mp4'));
        $this->assertSame(4000, (new UploadPolicy(4000, 10))->mediaMaxBytes, 'never lower than the general limit');
        $this->assertStringNotContainsString('.mp4', UploadPolicy::acceptAttribute(false));
        $this->assertSame('.mp3,.m4a,.ogg,.opus,.wav', UploadPolicy::mediaAcceptAttribute('audio'));
    }

    public function testRangesOnlyApplyToFiles(): void
    {
        $this->assertSame(200, Response::html('x')->forRange('bytes=0-1')->status);
        $file = Response::file(self::MEDIA . 'tone.mp3', 'audio/mpeg', 'tone.mp3', true);
        $this->assertSame(200, $file->forRange('items=0-1')->status);
        $this->assertSame(200, $file->forRange('bytes=-')->status);
        $this->assertSame(416, $file->forRange('bytes=-0')->status);
        $this->assertSame(416, $file->forRange('bytes=9-3')->status);
        $size = (int) filesize(self::MEDIA . 'tone.mp3');
        $this->assertSame([0, $size - 1], $file->forRange('bytes=0-999999999')->byteRange(), 'clipped to the file');

        $big = (string) tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($big, str_repeat('x', Response::MAX_OPEN_RANGE + 1000));
        $open = Response::file($big, 'video/mp4', 'big.mp4', true)->forRange('bytes=10-');
        $this->assertSame([10, Response::MAX_OPEN_RANGE + 9], $open->byteRange(), 'open-ended ranges come in pieces');
        $this->assertSame('bytes 10-' . (Response::MAX_OPEN_RANGE + 9) . '/' . (Response::MAX_OPEN_RANGE + 1000), $open->header('Content-Range'));
        @unlink($big);
    }

    public function testClassroomServerNamesAreBareHosts(): void
    {
        $this->assertTrue(ClassroomSettings::isHostName('live.dmlschooloftechnology.org'));
        foreach (['https://live.example.org', 'live.example.org/x', 'live.example.org:8443', 'localhost', "live.example.org\r\nX: y", 'LIVE.example.org', 'a b.org'] as $bad) {
            $this->assertFalse(ClassroomSettings::isHostName($bad), $bad);
        }
    }

    public function testPassesAreStandardHs256Tokens(): void
    {
        $settings = new ClassroomSettings(1, 'live.example.org', 'dml-lms', true, '2026-11-02 09:00:00');
        $token = JitsiToken::make($settings, str_repeat('k', 40), 'dml-abc', ['id' => '1-2', 'name' => 'Ada Ọkafor', 'moderator' => false], 1000, 2000);
        [$header, $payload, $signature] = explode('.', $token);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode((string) base64_decode(strtr($header, '-_', '+/')), true));
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, str_repeat('k', 40), true)), '+/', '-_'), '='), $signature);
        $claims = JitsiToken::verify($token, str_repeat('k', 40));
        $this->assertSame('Ada Ọkafor', $claims['context']['user']['name'] ?? null);
        $this->assertSame(2000, $claims['exp'] ?? null);
        $this->assertNull(JitsiToken::verify($token, str_repeat('x', 40)), 'another secret does not verify');
        $this->assertStringNotContainsString(str_repeat('k', 40), $token);
    }
}
