<?php

declare(strict_types=1);

namespace App\Modules\Files;

interface VirusScanner
{
    /**
     * @return 'clean'|'unscanned'
     * @throws InfectedFile when a threat is found
     * @throws ScannerUnavailable when scanning is configured but cannot be done
     */
    public function scan(string $path): string;
}
