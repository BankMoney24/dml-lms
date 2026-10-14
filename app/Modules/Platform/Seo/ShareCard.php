<?php

declare(strict_types=1);

namespace App\Modules\Platform\Seo;

use App\Modules\Tenancy\Tenant;

/**
 * Link-preview images (1200 × 630) for social networks and chat apps: the
 * school's logo and name, the page or course title, and the logo's pixels on
 * navy. Drawn with GD the first time they are asked for, then kept on disk
 * until the content changes. Without GD and FreeType the static brand image
 * is served instead, so previews never break.
 */
final class ShareCard
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    public function __construct(private readonly string $cacheDir, private readonly string $fontDir)
    {
    }

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext');
    }

    /** Changes whenever what the school's card shows changes, so previews refresh. */
    public static function version(Tenant $tenant): string
    {
        return substr(hash('sha256', $tenant->name . '|' . $tenant->tagline . '|' . ($tenant->hasLogo() ? $tenant->logoVersion() : '')), 0, 10);
    }

    /**
     * Path to a PNG for this card, drawing it if needed.
     *
     * @param ?string $logoPath the school's uploaded logo, if any
     * @param string $key   stable name for the cache, e.g. "course-12"
     * @param string $title the large text (school name on the site card)
     * @param string $kicker the small line above it ("Course", "About us")
     * @param string $seed  drives the pixel pattern, so each course keeps its own
     */
    public function render(Tenant $tenant, ?string $logoPath, string $key, string $title, string $kicker, string $seed, string $version): ?string
    {
        if (!self::available()) {
            return null;
        }
        $dir = rtrim($this->cacheDir, '/') . '/' . $tenant->id;
        $file = $dir . '/' . preg_replace('/[^a-z0-9-]/', '', strtolower($key)) . '-' . substr(hash('sha256', $version . '|' . $title . '|' . $kicker . '|' . self::version($tenant)), 0, 16) . '.png';
        if (is_file($file)) {
            return $file;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $navy = (int) imagecolorallocate($img, 0x16, 0x1a, 0x25);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $navy);
        $this->pixels($img, $seed);

        $bold = $this->fontDir . '/DejaVuSans-Bold.ttf';
        $regular = $this->fontDir . '/DejaVuSans.ttf';
        $white = (int) imagecolorallocate($img, 0xff, 0xff, 0xff);
        $soft = (int) imagecolorallocate($img, 0xa7, 0xb0, 0xc2);
        $sky = (int) imagecolorallocate($img, 0x23, 0xa1, 0xde);
        $red = (int) imagecolorallocate($img, 0xd5, 0x2f, 0x2f);

        // The school: logo on a white plate when there is one, then its name.
        $x = 80;
        $y = 80;
        $plateEnd = $x;
        if ($logoPath !== null && is_file($logoPath)) {
            $logo = @imagecreatefromstring((string) file_get_contents($logoPath));
            if ($logo !== false) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                $h = 72;
                $w = (int) min(260, round($lw * $h / max(1, $lh)));
                $h = (int) round($lh * $w / max(1, $lw));
                $plate = (int) imagecolorallocate($img, 0xff, 0xff, 0xff);
                imagefilledrectangle($img, $x, $y, $x + $w + 28, $y + $h + 28, $plate);
                imagecopyresampled($img, $logo, $x + 14, $y + 14, 0, 0, $w, $h, $lw, $lh);
                $plateEnd = $x + $w + 28 + 28;
            }
        }
        if ($title !== $tenant->name) {
            $schoolSize = 26;
            imagettftext($img, $schoolSize, 0, $plateEnd, $y + 58, $white, $bold, $this->fit($tenant->name, $bold, $schoolSize, self::WIDTH - $plateEnd - 80));
        }

        // The page: a small label, then the title in up to three lines.
        imagefilledrectangle($img, $x, 300, $x + 56, 306, $red);
        if ($kicker !== '') {
            imagettftext($img, 24, 0, $x + 76, 314, $sky, $bold, $this->fit($kicker, $bold, 24, 700));
        }
        $size = 54;
        $lines = $this->wrap($title, $bold, $size, 760, 3);
        while (count($lines) > 3 || ($size > 38 && $this->widest($lines, $bold, $size) > 760)) {
            $size -= 4;
            $lines = $this->wrap($title, $bold, $size, 760, 3);
        }
        $lineY = 300 + 34 + (int) ($size * 1.25);
        foreach (array_slice($lines, 0, 3) as $line) {
            imagettftext($img, $size, 0, $x, $lineY, $white, $bold, $line);
            $lineY += (int) ($size * 1.3);
        }
        if ($tenant->tagline !== '' && $lineY < self::HEIGHT - 40) {
            imagettftext($img, 22, 0, $x, self::HEIGHT - 56, $soft, $regular, $this->fit($tenant->tagline, $regular, 22, 760));
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagepng($img, $tmp, 6);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);

            return null;
        }

        return $file;
    }

    /** The logo's square pixels gathering towards the right edge, as on course covers. */
    private function pixels(\GdImage $img, string $seed): void
    {
        $state = crc32($seed) ?: 1;
        $next = static function () use (&$state): float {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0xFFFFFFFF;

            return ($state & 0xFFFFFF) / 0x1000000;
        };
        $colours = [
            (int) imagecolorallocate($img, 0x16, 0x70, 0xad),
            (int) imagecolorallocate($img, 0x23, 0xa1, 0xde),
            (int) imagecolorallocate($img, 0x33, 0x40, 0x5a),
            (int) imagecolorallocate($img, 0xd5, 0x2f, 0x2f),
        ];
        $cell = 30;
        $cols = 12;
        $startX = self::WIDTH - $cols * $cell - 20;
        for ($cx = 0; $cx < $cols; $cx++) {
            $density = 0.05 + 0.7 * (($cx / ($cols - 1)) ** 1.6);
            for ($cy = 0; $cy < (int) (self::HEIGHT / $cell); $cy++) {
                if ($next() < $density) {
                    $r = $next();
                    $c = $r < 0.06 ? $colours[3] : ($r < 0.45 ? $colours[1] : ($r < 0.8 ? $colours[0] : $colours[2]));
                    $px = $startX + $cx * $cell;
                    $py = 10 + $cy * $cell;
                    imagefilledrectangle($img, $px, $py, $px + $cell - 8, $py + $cell - 8, $c);
                }
            }
        }
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $width, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->width($try, $font, $size) <= $width || $line === '') {
                $line = $try;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines && $size <= 38) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->fit($lines[$maxLines - 1] . ' …', $font, $size, $width);
        }

        return array_values($lines);
    }

    /** @param list<string> $lines */
    private function widest(array $lines, string $font, int $size): int
    {
        return $lines === [] ? 0 : max(array_map(fn (string $l): int => $this->width($l, $font, $size), $lines));
    }

    private function fit(string $text, string $font, int $size, int $width): string
    {
        if ($this->width($text, $font, $size) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->width($text . '…', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text) . '…';
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }
}
