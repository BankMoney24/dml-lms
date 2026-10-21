<?php

declare(strict_types=1);

namespace App\Modules\Files;

/** clamd refused the file because it is bigger than its StreamMaxLength. */
final class ScanTooLarge extends ScannerUnavailable
{
}
