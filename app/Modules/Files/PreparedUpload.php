<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Core\Http\UploadedFile;

/** An upload that passed every check and is ready to be stored. */
final class PreparedUpload
{
    public function __construct(
        public readonly UploadedFile $upload,
        public readonly string $name,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $scanStatus,
    ) {
    }
}
