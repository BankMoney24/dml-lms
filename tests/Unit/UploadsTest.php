<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Response;
use App\Core\Http\UploadedFile;
use App\Modules\Files\ClamdScanner;
use App\Modules\Files\FileService;
use App\Modules\Files\InfectedFile;
use App\Modules\Files\ScannerUnavailable;
use PHPUnit\Framework\TestCase;

final class UploadsTest extends TestCase
{
    public function testPhpFilesArrayIsNormalisedForSingleAndMultipleFields(): void
    {
        $single = UploadedFile::listFromPhp(['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 10, 'error' => 0]);
        $this->assertCount(1, $single);
        $this->assertSame('a.pdf', $single[0]->clientName);

        $multiple = UploadedFile::listFromPhp([
            'name' => ['a.txt', ''], 'tmp_name' => ['/tmp/a', ''], 'size' => [3, 0], 'error' => [0, UPLOAD_ERR_NO_FILE],
        ]);
        $this->assertCount(2, $multiple);
        $this->assertTrue($multiple[0]->wasSent());
        $this->assertFalse($multiple[1]->wasSent(), 'an empty slot of a multiple picker');
        $this->assertFalse((new UploadedFile('x', '/etc/passwd', 10))->isOk(), 'a path that was not uploaded is never trusted');
        $this->assertStringContainsString('up to 10 MB', (new UploadedFile('big.zip', '', 0, UPLOAD_ERR_INI_SIZE))->errorMessage(10));
    }

    public function testPolicyJudgesContentsNotNames(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not installed');
        }
        $policy = new \App\Modules\Files\UploadPolicy(1048576);
        $file = (string) tempnam(sys_get_temp_dir(), 'pol');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();
        $this->assertNull($policy->problemWith($file, 'zip'));
        $this->assertNotNull($policy->problemWith($file, 'pdf'));
        file_put_contents($file, "Grüße, ẹ káàbọ̀\nline two\n");
        $this->assertNull($policy->problemWith($file, 'txt'), 'UTF-8 text');
        file_put_contents($file, "\xff\xfe\x00b\x00a\x00d");
        $this->assertNotNull($policy->problemWith($file, 'txt'), 'not UTF-8 / binary');

        // Packet Tracer files (ADR 0035): scrambled binary is accepted; anything libmagic recognises is not.
        file_put_contents($file, random_bytes(4096));
        $this->assertNull($policy->problemWith($file, 'pka'));
        $this->assertNull($policy->problemWith($file, 'pkt'));
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('evil.exe', 'MZ');
        $zip->close();
        $this->assertNotNull($policy->problemWith($file, 'pka'), 'an archive renamed .pka is refused');
        file_put_contents($file, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\x00", 48) . 'PE');
        $this->assertNotNull($policy->problemWith($file, 'pkt'), 'a Windows program renamed .pkt is refused');
        $this->assertSame('application/octet-stream', \App\Modules\Files\UploadPolicy::servedType('pka'));
        $this->assertFalse(\App\Modules\Files\UploadPolicy::opensInBrowser('pkt'), 'always a download');
        unlink($file);
    }

    public function testNamesAreCleaned(): void
    {
        $this->assertSame('passwd', FileService::cleanName('../../etc/passwd'));
        $this->assertSame('evil.pdf', FileService::cleanName("C:\\Users\\x\\ev\"il\x00.pdf"));
        $this->assertSame('file', FileService::cleanName('...'));
        $long = FileService::cleanName(str_repeat('a', 300) . '.pdf');
        $this->assertLessThanOrEqual(150, mb_strlen($long));
        $this->assertStringEndsWith('.pdf', $long);
    }

    public function testDownloadHeadersCarryBothFilenameForms(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($path, 'x');
        $response = Response::file($path, 'application/pdf', 'Ẹ̀kọ́ 1 "notes".pdf', false);

        $this->assertSame('attachment; filename="_k_ 1 _notes_.pdf"; filename*=UTF-8\'\'' . rawurlencode('Ẹ̀kọ́ 1 "notes".pdf'), $response->header('Content-Disposition'));
        $this->assertSame('1', $response->header('Content-Length'));
        unlink($path);
    }

    /** @param string $reply what the fake clamd answers */
    private function scanWith(string $reply): string
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed for the fake clamd.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error ?? 'could not listen');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid === 0) {
            $conn = stream_socket_accept($server, 5);
            if ($conn !== false) {
                fread($conn, 10); // zINSTREAM\0
                while (true) {
                    $len = unpack('N', (string) fread($conn, 4));
                    $n = is_array($len) ? (int) $len[1] : 0;
                    if ($n === 0) {
                        break;
                    }
                    while ($n > 0) {
                        $n -= strlen((string) fread($conn, $n));
                    }
                }
                fwrite($conn, $reply);
                fclose($conn);
            }
            if (function_exists('posix_getpid')) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            exit(0);
        }
        fclose($server);
        $file = (string) tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($file, str_repeat('data', 40000));
        try {
            return (new ClamdScanner('127.0.0.1', $port, 5))->scan($file);
        } finally {
            unlink($file);
            pcntl_waitpid($pid, $status);
        }
    }

    public function testClamdCleanInfectedAndBroken(): void
    {
        $this->assertSame('clean', $this->scanWith("stream: OK\0"));

        try {
            $this->scanWith("stream: Eicar-Test-Signature FOUND\0");
            $this->fail('should be infected');
        } catch (InfectedFile $e) {
            $this->assertSame('Eicar-Test-Signature', $e->getMessage());
        }

        $this->expectException(\App\Modules\Files\ScanTooLarge::class); // still fails closed: a ScannerUnavailable
        $this->scanWith("INSTREAM size limit exceeded. ERROR\0");
    }

    public function testClamdUnreachableFailsClosed(): void
    {
        $this->expectException(ScannerUnavailable::class);
        (new ClamdScanner('127.0.0.1', 1, 2))->scan(__FILE__);
    }
}
