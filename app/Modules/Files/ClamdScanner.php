<?php

declare(strict_types=1);

namespace App\Modules\Files;

/**
 * Streams a file to ClamAV's daemon (clamd) over TCP with the INSTREAM
 * command. Fails closed: if clamd cannot be reached the upload is refused,
 * because the administrator asked for scanning.
 */
final class ClamdScanner implements VirusScanner
{
    private const CHUNK = 65536;

    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeoutSeconds = 30)
    {
    }

    /** True when clamd answers PING within two seconds. */
    public function ping(): bool
    {
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $error, 2);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 2);
        fwrite($socket, "zPING\0");
        $reply = trim((string) fread($socket, 64), "\0\r\n ");
        fclose($socket);

        return $reply === 'PONG';
    }

    public function scan(string $path): string
    {
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $error, 5);
        if ($socket === false) {
            throw new ScannerUnavailable('Virus scanner unreachable: ' . $error);
        }
        stream_set_timeout($socket, $this->timeoutSeconds);
        $file = fopen($path, 'rb');
        if ($file === false) {
            fclose($socket);
            throw new ScannerUnavailable('Cannot read the file to scan it.');
        }
        try {
            fwrite($socket, "zINSTREAM\0");
            while (!feof($file)) {
                $chunk = (string) fread($file, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        // Replies: "stream: OK", "stream: Eicar-Signature FOUND", "INSTREAM size limit exceeded. ERROR"
        if (str_ends_with($reply, 'OK')) {
            return 'clean';
        }
        if (str_ends_with($reply, 'FOUND')) {
            throw new InfectedFile(trim(substr($reply, (int) strpos($reply, ':') + 1, -strlen('FOUND'))));
        }
        if (str_contains($reply, 'size limit')) {
            throw new ScanTooLarge('Virus scanner limit: ' . $reply);
        }
        throw new ScannerUnavailable('Virus scanner error: ' . ($reply === '' ? 'no reply' : $reply));
    }
}
