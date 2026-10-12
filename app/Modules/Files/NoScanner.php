<?php

declare(strict_types=1);

namespace App\Modules\Files;

/** Used when UPLOAD_SCANNER is not set: files are stored and marked "unscanned". */
final class NoScanner implements VirusScanner
{
    public function scan(string $path): string
    {
        return 'unscanned';
    }
}
